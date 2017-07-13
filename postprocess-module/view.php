<?php global $path; ?>

<style>
</style>

<script type="text/javascript" src="<?php echo $path; ?>Modules/feed/feed.js"></script>
<br>
<h2>Post Processor</h2>

<p>Rather than process inputs as data arrives in emoncms such as calculating cumulative kWh data from power data with the power to kWh input process, this module can be used to do these kind of processing steps after having recorded base data such as power data for some time. This removes the reliance on setting up everything right in the first instance providing the flexibility to recalculate processed feeds at a later date.</p>

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
