<?php global $path; ?>
<script src="<?php echo $path; ?>Lib/vue.min.js"></script>
<script src="<?php echo $path; ?>Modules/feed/feed.js"></script>
<br>

<h2>Post Processor</h2>
<p>Rather than process inputs as data arrives in emoncms such as calculating cumulative kWh data from power data with the power to kWh input process, this module can be used to do these kind of processing steps after having recorded base data such as power data for some time. This removes the reliance on setting up everything right in the first instance providing the flexibility to recalculate processed feeds at a later date.</p>

<hr>
<div id="app">
    <h3>My processes</h3>

    <table class="table">
        <tr>
            <th>Process</th>
            <th>Parameters</th>

        </tr>
        <tr v-for="item in process_list">
            <td>{{item.process}}</td>
            <td>
                <span v-for="(param,key) in processes[item.process].settings">
                    <span v-if="param.type=='feed'">
                        <b>{{key}}:</b>{{item[key].id}}:{{item[key].name}}<br>
                    </span>
                    <span v-if="param.type=='newfeed'">
                        <b>{{key}}:</b>{{item[key].id}}:{{item[key].name}}<br>
                    </span>
                    <span v-if="param.type=='value'">
                        <b>{{key}}:</b>{{item[key]}}<br>
                    </span>
                </span>
            </td>
        </tr>
    </table>

    <div class="alert" v-if="process_list.length==0"><i class="icon-th-list"></i> <b>No processes created yet</b></div>

    <h3>Create new</h3>

    <select v-model="new_process_select" @change="new_process_selected">
        <option value="none">SELECT PROCESS:</option>
        <optgroup v-for="(group,groupname) in processes_by_group" v-bind:label="groupname">
            <option v-for="(item,index) in group" >{{index}}</option>
        </optgroup>
    </select>

    <div v-if="processes[new_process_select]!=undefined">
        <span v-for="(param,key) in processes[new_process_select].settings">
            <div v-if="param.type=='feed' || param.type=='newfeed'">
                <b>{{param.short}}</b><br>
                <div class="input-append input-prepend">
                    <select v-model="new_process[key].id" @change="new_process_update">
                        <option value="none" v-if="param.type=='feed'">SELECT FEED:</option>
                        <option value="create" v-if="param.type=='newfeed'">CREATE NEW FEED:</option>
                        <optgroup v-for="(tag,tagname) in feeds_by_tag" v-bind:label="tagname">
                            <option v-for="(feed,feedid) in tag" v-bind:value="feedid" v-if="feed.engine==5">{{feed.name}}</option>
                        </optgroup>
                    </select>
                    <input type="text" v-if="new_process[key].id=='create'" v-model="new_process[key].tag" placeholder="Tag" style="width:100px"/>
                    <input type="text" v-if="new_process[key].id=='create'" v-model="new_process[key].name" placeholder="Name" style="width:100px" />
                </div>
            </div>
            <div v-if="param.type=='value'">
                <b>{{param.short}}</b><br>
                <input type="text" v-model="new_process[key]" @change="new_process_update">
            </div>
        </span>
    </div>

    <br>

    <button class="btn" v-if="new_process_create">Create</button>

</div>

<!--
<hr>
<button id="getlog" type="button" class="btn btn-info" data-toggle="button" aria-pressed="false" autocomplete="off" style="float:right; margin-top:10px"><?php echo _('Auto refresh'); ?></button>
<h3>Logger</h3>
<div id="logpath"></div>
<pre id="logreply-bound" class="log"><div id="logreply"></div></pre>
--->

<script>
    var processes = <?php echo json_encode($processes); ?>;
    // sort by group
    var processes_by_group = {};
    for (var key in processes) {
        var group = processes[key].group;
        if (processes_by_group[group]==undefined) processes_by_group[group] = {};
        processes_by_group[group][key] = processes[key];
    }

    var feeds = feed.list();
    // feeds by tag
    var feeds_by_tag = {};
    for (var z in feeds) {
        var feed = feeds[z];
        if (feeds_by_tag[feed.tag]==undefined) feeds_by_tag[feed.tag] = {};
        feeds_by_tag[feed.tag][feed.id] = feed;
    }

    var app = new Vue({
        el: '#app',
        data: {
            feeds_by_tag: feeds_by_tag,
            processes: processes,
            processes_by_group: processes_by_group,
            process_list: [],
            new_process_select: 'none',
            new_process: {},
            new_process_create: false
        },
        methods: {
            new_process_selected: function() {
                this.new_process = {};
                for (var key in this.processes[this.new_process_select].settings) {
                    if (this.processes[this.new_process_select].settings[key].type=='feed') {
                        this.new_process[key] = {id:"none"};
                    }
                    if (this.processes[this.new_process_select].settings[key].type=='newfeed') {
                        this.new_process[key] = {id:"create", tag:"", name:""};
                    }
                    if (this.processes[this.new_process_select].settings[key].type=='value') {
                        var default_value = this.processes[this.new_process_select].settings[key].default;
                        if (default_value==undefined) default_value = "";
                        this.new_process[key] = default_value;
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
                for (var key in this.new_process) {
                    if (this.new_process[key].id=="none") valid = false;
                    if (this.new_process[key].id=="create") valid = false;
                }
                app.new_process_create = valid;
            }
        }
    });

    // Load process list using jquery
    $.ajax({ url: path+"postprocess/list", dataType: "json", async: false, success: function(result) {
        app.process_list = result;
    }});

</script>