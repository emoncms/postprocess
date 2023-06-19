// sort by group
var processes_by_group = {};
for (var key in processes) {
    var group = processes[key].group;
    if (processes_by_group[group]==undefined) processes_by_group[group] = {};
    processes_by_group[group][key] = processes[key];
}

var app = new Vue({
    el: '#app',
    data: {
        feeds_by_id: {},
        feeds_by_tag: {},
        processes: processes,
        processes_by_group: processes_by_group,
        process_list: [],
        new_process_select: 'none',
        new_process: {},
        new_feed: {},
        new_process_mode: 'recent',
        new_process_start: 0,
        new_process_create: false,
        mode: 'create',
        selected_process: -1,
        formula_feed_finder_id: 'none',
        new_process_error: ''
    },
    methods: {
        new_process_selected: function() {
            this.new_process = {};
            for (var key in this.processes[this.new_process_select].settings) {
                let setting = this.processes[this.new_process_select].settings[key];
                if (setting.default==undefined) setting.default = "";

                if (setting.type=='feed') {
                    this.new_process[key] = 'none';
                }
                if (setting.type=='newfeed') {
                    if (setting.default_tag==undefined) setting.default_tag = "";
                    this.new_process[key] = 'create';
                    this.new_feed[key] = {tag: "postprocess", name: setting.default};
                }
                if (setting.type=='value') {
                    this.new_process[key] = setting.default;
                }
                if (setting.type=='timezone') {
                    this.new_process[key] = setting.default;
                }
                if (setting.type=='formula') {
                    this.new_process[key] = '';
                }
            }
            this.validate_new_process();
            
        },
        new_process_update: function() {
            this.new_process_create = true;
            this.validate_new_process();
        },
        validate_new_process: function() {
            var valid = true;

            for (var key in this.processes[this.new_process_select].settings) {
                let setting = this.processes[this.new_process_select].settings[key];

                if (setting.type=='feed') {
                    if (this.new_process[key]=="none") {
                        valid = false;
                    }
                }

                if (setting.type=='newfeed') {
                    if (this.new_process[key]=="create") {
                        if (this.new_feed[key].name=="") valid = false;
                        if (feed_exists(this.new_feed[key].tag, this.new_feed[key].name)) valid = false;
                    }
                }

                if (setting.type=='value') {
                    // check if numeric
                    if (this.new_process[key]=="" || isNaN(this.new_process[key])) valid = false;
                }

                if (setting.type=='timezone') {
                    // check if timezone
                    if (this.new_process[key]=="" || !moment.tz.zone(this.new_process[key])) valid = false;
                }

                if (setting.type=='formula') {
                    var formula = this.new_process[key];
                    var regex1 = /[^-\+\*\/\dfmax,\.\(\)]/;
                    var regex2 = /f/;
                    if (formula.match(regex1) || !formula.match(regex2) ){
                        valid = false;
                    }
                }
            }
            app.new_process_create = valid;
        },
        create_process: function() {

            var params = {};
            for (var key in this.processes[this.new_process_select].settings) {
                let setting = this.processes[this.new_process_select].settings[key];
                
                if (setting.type=='feed') {
                    params[key] = this.new_process[key];
                }

                if (setting.type=='newfeed') {
                    if (this.new_process[key]=="create") {
                        var result = feed.create(this.new_feed[key].tag, this.new_feed[key].name, 5, {interval:3600}, '');
                        if (result.success) {
                            params[key] = result.feedid;
                        } else {
                            app.new_process_error = result.message;
                            return false;
                        }
                    } else {
                        params[key] = this.new_process[key];
                    } 
                }

                if (setting.type=='value') {
                    params[key] = this.new_process[key];
                }

                if (setting.type=='timezone') {
                    params[key] = this.new_process[key];
                }

                if (setting.type=='formula') {
                    params[key] = this.new_process[key];
                }
            }
            reload_feeds();

            // These are added to all processes and control
            // how much of the input data is processed
            params['process_mode'] = this.new_process_mode;
            params['process_start'] = this.new_process_from;

            var url = path+"postprocess/create?process="+this.new_process_select;
            if (this.mode=='edit') {
                url = path+"postprocess/edit?process="+this.new_process_select+"&processid="+this.selected_process;
            }

            $.ajax({
                type: "POST",
                url: url,
                data: JSON.stringify(params),
                dataType: 'json',
                async: false,
                success: function(result) {
                    if (result.success) {
                        app.new_process_create = false;
                        app.new_process_select = 'none';
                        app.new_process_error = '';
                        app.new_process = {};
                        app.mode = 'create';
                        load_process_list();
                    } else {
                        app.new_process_error = result.message;
                    }
                }
            });
        },
        delete_process: function(index) {
            if (confirm("Are you sure you want to delete this process?")) {
                $.ajax({
                    url: path+"postprocess/remove?processid="+index,
                    dataType: 'json',
                    async: false,
                    success: function(result) {
                        if (result.success) {
                            app.process_list.splice(index, 1);
                        } else {
                            alert("Error deleting process: "+result.message);
                        }
                    }
                });
            }
        },
        edit_process: function(index) {
            // load process to new process form
            app.new_process_select = app.process_list[index].process;
            app.new_process = app.process_list[index];
            app.new_process_create = true;
            app.mode = 'edit';
            app.selected_process = index;
            app.new_process_mode = app.process_list[index].process_mode;
            app.new_process_from = app.process_list[index].process_start;
        },
        run_process: function(index) {
            $.ajax({
                url: path+"postprocess/run?processid="+index,
                dataType: 'json',
                async: true,
                success: function(result) {
                    if (result.success) {
                        alert("Success: "+result.message);
                    } else {
                        alert("Error starting process: "+result.message);
                    }
                }
            });
        },
        formula_feed_finder_change: function() {
            if (this.formula_feed_finder_id!='none' && !isNaN(this.formula_feed_finder_id)) {
                this.new_process['formula'] += "f"+this.formula_feed_finder_id;
            }
        }
    }
});

reload_feeds();
load_process_list();
// Load process list using jquery
function load_process_list() {
    $.ajax({ url: path+"postprocess/list", dataType: "json", async: true, success: function(result) {
        app.process_list = result;
    }});
}

function feed_exists(tag, name) {
    for (var z in feeds) {
        if (feeds[z].tag==tag && feeds[z].name==name) return true;
    }
    return false;
}

function reload_feeds() {
    $.ajax({ url: path+"feed/list.json?meta=1", dataType: "json", async: false, success: function(result) {
        feeds = result;
        // feeds by id
        app.feeds_by_id = {};
        for (var z in feeds) {
            app.feeds_by_id[feeds[z].id] = feeds[z];
        }
        // feeds by tag
        app.feeds_by_tag = {};
        for (var z in feeds) {
            var f = feeds[z];
            if (app.feeds_by_tag[f.tag]==undefined) app.feeds_by_tag[f.tag] = {};
            app.feeds_by_tag[f.tag][f.id] = f;
        }
    }});
}