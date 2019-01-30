var feeds = feed.list();

//--------------------------------------------------------------------------------------------
// Create/register new process
//--------------------------------------------------------------------------------------------

var processes = {};
$.ajax({ url: path+"postprocess/processes", dataType: 'json', async: false, success: function(result) {processes = result;} });

var process_select = "<option value=''>Select process:</option>";
for (var z in processes) {
    process_select += "<option value="+z+">"+z+"</option>";
}
$("#process_select").html(process_select);

$("#process_select").change(function(){
   var process = $(this).val();
   
   if (process=="") {
       $("#process_options").html("");
       $("#create").hide();
       return false;
   }
   
   var options = "";
   for (var z in processes[process]) {
       
       options += "<b>"+processes[process][z]["short"]+"</b><br>";
       if (processes[process][z]["type"]=="feed") {
            options += "<select class='process_option' option="+z+">";
            //for (var n in feeds) options += "<option value="+feeds[n].id+">"+feeds[n].name+"</option>";
            
            var datatype = 1;  // 0:UNDEFINED, 1:REALTIME, 2:DAILY, 3:HISTOGRAM
            var feedgroups = [];
            for (n in feeds) {
                if (feeds[n].datatype == datatype) {
                    if (parseInt(feeds[n].engine) == 7) continue; // Dont list virtual feed
                    var group = (feeds[n].tag === null ? "NoGroup" : feeds[n].tag);
                    if (group!="Deleted") {
                        if (!feedgroups[group]) feedgroups[group] = []
                        feedgroups[group].push(feeds[n]);
                    }
                }
            }
            var out = "<option value=-1>CHOOSE FEED:</option>";
            for (n in feedgroups) {
                out += "<optgroup label='"+n+"'>";
                for (p in feedgroups[n]) {
                     out += "<option value="+feedgroups[n][p]['id']+">"+feedgroups[n][p].name+"</option>";
                }
            out += "</optgroup>";
            }
            options+=out;
            options += "</select><br>";
       }
       
       if (processes[process][z]["type"]=="newfeed") {
           var suggestion = "";
           options += "<input class='process_option' option="+z+" type='text' value='"+suggestion+"' /><br>";
       }
       
       if (processes[process][z]["type"]=="value") {
           options += "<input class='process_option' option="+z+" type='text' /><br>";
       }
   }
   $("#process_options").html(options);
   validate();
});

$("#process_options").on("change",".process_option",function(){
    validate();
});

function validate()
{
    var valid = true;
    var process = $("#process_select").val();
    for (var z in processes[process]) {
        if (processes[process][z]["type"]=="newfeed") {
            var name = $(".process_option[option="+z+"]").val();
            
            var validfeed = true;
            for (var n in feeds) if (feeds[n].name==name) validfeed = false;
            if (name=="") validfeed = false;
            
            if (validfeed) {
                $(".process_option[option="+z+"]").css("background-color","#eeffee");
            } else {
                $(".process_option[option="+z+"]").css("background-color","#ffeeee");
                valid = false;
            }
        }
        
        if (processes[process][z]["type"]=="value") {
            var value = $(".process_option[option="+z+"]").val();
            if (value=="" || isNaN(value)) {
                $(".process_option[option="+z+"]").css("background-color","#ffeeee");
                valid = false;
            } else {
                $(".process_option[option="+z+"]").css("background-color","#eeffee");
            }
        }
    }
    
    if (valid) $("#create").show(); else $("#create").hide();
    
    return valid;
}

$("#create").click(function(){
    var process = $("#process_select").val();
    var params = {};
    
    if (!validate()) return false;
    
    for (var z in processes[process]) {
        params[z] = $(".process_option[option="+z+"]").val()
    }
    
    $.ajax({
        type: "POST",
        url: path+"postprocess/create?process="+process, 
        data: JSON.stringify(params),
        dataType: 'text', 
        async: false, 
        success: function(result) { 
            console.log(result); 
        } 
    });
    
    $("#create").hide();
    
    processlist_update();
});


//--------------------------------------------------------------------------------------------
// Process list
//--------------------------------------------------------------------------------------------

var processlist = [];
processlist_update();
setInterval(processlist_update,5000);

function processlist_update()
{
    processlist = [];
    $.ajax({ url: path+"postprocess/list", dataType: 'json', async: true, success: function(data) 
    {
        processlist = data;

        var out = "";
        for (z in processlist) {
        
            var process = processlist[z].process;
        
            out += "<tr>";
            out += "<td>"+process+"</td>";
            
            out += "<td>";
            var base_npoints = 0;
            var out_npoints = 0;
            //number of feeds in the postprocessing
            var nbf=0;
            var fstart_time=[];
            var ftime=[];
            var finterval=[];
            for (var key in processes[process]) {
                out += "<div style='width:250px; float:left'><b>"+key+":</b>";
                if (processes[process][key].type=="feed" || processes[process][key].type=="newfeed") 
                    out += processlist[z][key].id+":"+processlist[z][key].name;
                //if value, should print it
                if (processes[process][key].type=="value")
                    out += processlist[z][key];
                out += "</div>";
                
                //rework by alexandre CUER - not totally satisfying anyway
                if (processes[process][key].type=="feed") {
                    //base_npoints = processlist[z][key].npoints;
                    fstart_time[nbf]=processlist[z][key].start_time;
                    ftime[nbf]=processlist[z][key].time;
                    finterval[nbf]=processlist[z][key].interval;
                    nbf+=1;
                }

                if (processes[process][key].type=="newfeed") {
                    out_npoints = processlist[z][key].npoints;
                }
            }
            //console.log(feeds_npoints);
            base_npoints=Math.round((Math.min(...ftime)-Math.max(...fstart_time))/Math.max(...finterval));
            out += "</td>";
            
            var points_behind = base_npoints - out_npoints;
            out += "<td>"+points_behind+" points behind</td>";
            out += "<td><button class='btn runprocess' processid="+z+" >Run process</button></td>";
            out += "</tr>";
        }
        if (out=="") $("#noprocessesalert").show(); else $("#noprocessesalert").hide();
        
        $("#processlist").html(out); 
    } 
    });
}

$("#processlist").on("click",".runprocess",function(){
    var z = $(this).attr("processid");
    var process = processlist[z].process;
    
    var params = {};
    
    for (var key in processes[process]) {
        
        if (processes[process][key].type=="feed") {
            params[key] = processlist[z][key].id;
        }
        
        if (processes[process][key].type=="newfeed") {
            params[key] = processlist[z][key].id;
        }
        
        if (processes[process][key].type=="value") {
            params[key] = processlist[z][key];
        }
    }

    $.ajax({
        type: "POST",
        url: path+"postprocess/update?process="+process, 
        data: JSON.stringify(params),
        dataType: 'text', 
        async: false, 
        success: function(result) { 
            console.log(result); 
        } 
    });
});
