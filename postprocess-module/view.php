<?php global $path; ?>

<script type="text/javascript" src="<?php echo $path; ?>Modules/feed/feed.js"></script>
<br>
<style>
pre {
    width:100%;
    height:200px;
    margin:0px;
    padding:0px;
    color:#fff;
    background-color:#300a24;
    overflow: scroll;
    overflow-x: hidden;
}
</style>

<h2>Post Processor</h2>
<p>Rather than process inputs as data arrives in emoncms such as calculating cumulative kWh data from power data with the power to kWh input process, this module can be used to do these kind of processing steps after having recorded base data such as power data for some time. This removes the reliance on setting up everything right in the first instance providing the flexibility to recalculate processed feeds at a later date.</p>

<hr>
<button id="getlog" type="button" class="btn btn-info" data-toggle="button" aria-pressed="false" autocomplete="off" style="float:right; margin-top:10px"><?php echo _('Auto refresh'); ?></button>
<h3>Logger</h3>
<p>on file <?php global $log_location; echo "$log_location/postprocess.log"; ?></p>
<pre id="logreply-bound"><div id="logreply"></div></pre>

<hr>

<h3>My processes</h3>

<table class="table">
<tr>
    <th>Process</th>
    <th>Parameters</th>
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

<script>

var path = "<?php echo $path; ?>";

</script>

<script type="text/javascript" src="<?php echo $path; ?>Modules/postprocess/view.js"></script>
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
  $.ajax({ url: path+"postprocess/getlog", async: true, dataType: "text", success: function(result)
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
</script>
