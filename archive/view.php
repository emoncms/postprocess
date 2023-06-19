<?php global $path; ?>

<script type="text/javascript" src="<?php echo $path; ?>Modules/feed/feed.js"></script>
<br>

<h2>Post Processor</h2>
<p>Rather than process inputs as data arrives in emoncms such as calculating cumulative kWh data from power data with the power to kWh input process, this module can be used to do these kind of processing steps after having recorded base data such as power data for some time. This removes the reliance on setting up everything right in the first instance providing the flexibility to recalculate processed feeds at a later date.</p>

<hr>
<button id="getlog" type="button" class="btn btn-info" data-toggle="button" aria-pressed="false" autocomplete="off" style="float:right; margin-top:10px"><?php echo _('Auto refresh'); ?></button>
<h3>Logger</h3>
<div id="logpath"></div>
<pre id="logreply-bound" class="log"><div id="logreply"></div></pre>

<hr>

<h3>My processes</h3>

<table class="table">
<tr>
    <th>Process</th>
    <th>Parameters</th>
    <th></th>
    <th></th>
    <th></th>
    
</tr>
<tbody id="processlist"></tbody>
</table>

<div id="noprocessesalert" style="display:none" class="alert"><i class="icon-th-list"></i> <b>No processes created yet</b></div>

<h3>Create new</h3>

<select id="process_select"></select>

<div id="process_options"></div>
<button id="create" class="btn" style="display:none">Create</button>

<script type="text/javascript" src="<?php echo $path; ?>Modules/postprocess/view.js?v=4"></script>
<script>
var logrunning = false;
var refresher_log;
function refresherStart(func, interval){
  clearInterval(refresher_log);
  refresher_log = null;
  if (interval > 0) refresher_log = setInterval(func, interval);
}
getLog();
function getLog() {
  $.ajax({ url: path+"postprocess/getlog", async: true, dataType: "text", success(result)
    {
      $("#logreply").html(result);
      $("#logreply-bound").scrollTop = $("#logreply-bound").scrollHeight;
    }
  });
}
$("#getlog").click(function() {
  logrunning = !logrunning;
  if (logrunning) { refresherStart(getLog, 500); }
  else { refresherStart(getLog, 0);  }
});
//output the logfile path just above the log pre
$.ajax({ url: path+"postprocess/logpath", async: true, dataType: "text", success(result)
    {
      $("#logpath").html("<p>on file: "+result+"</p>");
    }
});

</script>
