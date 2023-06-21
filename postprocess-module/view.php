<?php global $path; ?>
<script src="<?php echo $path; ?>Lib/vue.min.js"></script>
<script src="<?php echo $path; ?>Modules/feed/feed.js"></script>
<br>

<h3>Post Processor</h3>

<div id="app">

    <div class="alert alert-info"><svg class="icon spinner11"><use xlink:href="#icon-spinner11"></use></svg> Process existing PHPFina feed data into new feeds.</div>

    <table class="table table-striped" v-if="process_list.length">
        <tr>
            <th>Process</th>
            <th>Parameters</th>
            <th>Process mode</th>
            <th>Status</th>
            <th>Actions</th>

        </tr>
        <tr v-for="(item,index) in process_list">
            <td :title="'Process ID: '+item.processid">{{processes[item.params.process].name}}</td>
            <td>
                <span v-for="(param,key) in processes[item.params.process].settings">
                    <span v-if="param.type=='feed'">
                        <b>{{key}}:</b>{{item.params[key]}}:
                        <span v-if="feeds_by_id[item.params[key]]!=undefined">{{feeds_by_id[item.params[key]].name}}</span>
                        <span v-else>Feed not found</span>
                        <br>
                    </span>
                    <span v-if="param.type=='newfeed'">
                        <b>{{key}}:</b>{{item.params[key]}}:
                        <span v-if="feeds_by_id[item.params[key]]!=undefined">{{feeds_by_id[item.params[key]].name}}</span>
                        <span v-else>Feed not found</span>
                        <br>
                    </span>
                    <span v-if="param.type=='value'">
                        <b>{{key}}:</b>{{item.params[key]}}<br>
                    </span>
                    <span v-if="param.type=='timezone'">
                        <b>{{key}}:</b>{{item.params[key]}}<br>
                    </span>
                    <span v-if="param.type=='formula'">
                        <b>{{key}}:</b>{{item.params[key]}}<br>
                    </span>
                </span>
                
            </td>
            <td>
                <span v-if="item.params.process_mode=='recent'">New data only</span>
                <span v-if="item.params.process_mode=='all'">Reprocess all</span>
                <span v-if="item.params.process_mode=='from'">Process from {{ item.process_start }}</span>
            </td>
            <td>
                <span v-if="item.status=='queued'" :title="time_ago(item.status_updated)" class="label label-info">Queued</span>
                <span v-if="item.status=='running'" :title="time_ago(item.status_updated)" class="label label-warning">Running</span>
                <span v-if="item.status=='finished'" :title="time_ago(item.status_updated)+'\n\n'+item.status_message" class="label label-success">Finished</span>
                <span v-if="item.status=='error'" :title="time_ago(item.status_updated)" class="label label-danger">Error: {{ item.status_message }}</span>
            <td>
                <button class="btn btn-success" @click="run_process(item.processid)">Run</button>
                <button class="btn btn-info" @click="edit_process(index)">Edit</button>
                <button class="btn btn-danger" @click="delete_process(item.processid)">Delete</button>
            </td>
        </tr>
    </table>

    <div class="alert" v-if="process_list.length==0"><p><b>No processes created yet</b></p>

    <p>Rather than process inputs as data arrives in emoncms such as calculating cumulative kWh data from power data with the power to kWh input process, this module can be used to do these kind of processing steps after having recorded base data such as power data for some time. This removes the reliance on setting up everything right in the first instance providing the flexibility to recalculate processed feeds at a later date.</p>
    </div>

    <div class="well" style="max-width:500px">
        <h4><span v-if="mode=='create'">Create new</span><span v-if="mode=='edit'">Edit process</span></h4>

        <select v-model="new_process_select" @change="new_process_selected">
            <option value="none">SELECT PROCESS:</option>
            <optgroup v-for="(group,groupname) in processes_by_group" v-bind:label="groupname">
                <option v-for="(item,key) in group" :value="key">{{item.name}}</option>
            </optgroup>
        </select>

        <div v-if="processes[new_process_select]!=undefined">

            <div class="alert alert-info" style="margin-bottom:15px">
                <span v-html="processes[new_process_select].description"></span>
            </div>

            <span v-for="(param,key) in processes[new_process_select].settings">
                <div v-if="param.type=='feed' || param.type=='newfeed'">
                    <b>{{param.short}}</b><br>
                    <div class="input-append input-prepend">
                        <select v-model="new_process[key]" @change="change_feed_select" style="width:150px">
                            <option value="none" v-if="param.type=='feed'">SELECT FEED:</option>
                            <option value="create" v-if="param.type=='newfeed'">CREATE NEW:</option>
                            <optgroup v-for="(tag,tagname) in feeds_by_tag" v-bind:label="tagname">
                                <option v-for="(feed,feedid) in tag" v-bind:value="feedid" v-if="feed.engine==5">{{feed.name}}</option>
                            </optgroup>
                        </select>
                        <input type="text" v-if="new_process[key]=='create'" v-model="new_feed[key].tag" placeholder="Tag" style="width:100px" @change="new_process_update"/>
                        <input type="text" v-if="new_process[key]=='create'" v-model="new_feed[key].name" placeholder="Name" style="width:150px" @change="new_process_update" />
                    </div>
                </div>
                <div v-if="param.type=='value' || param.type=='timezone'">
                    <b v-html="param.short"></b><br>
                    <input type="text" v-model="new_process[key]" @change="new_process_update">
                </div>
                <div v-if="param.type=='formula'">
                    <b v-html="param.short"></b><br>

                    <!-- Create a list of available feeds -->
                    <div class="input-prepend">
                        <span class="add-on">Feed finder</span>
                        <select style="width:150px" v-model="formula_feed_finder_id" @change="formula_feed_finder_change">
                            <option value="none">SELECT FEED:</option>
                            <optgroup v-for="(tag,tagname) in feeds_by_tag" v-bind:label="tagname">
                                <option v-for="(feed,feedid) in tag" v-bind:value="feedid" v-if="feed.engine==5">{{feed.name}}: f{{feed.id}}</option>
                            </optgroup>
                        </select>
                    </div>
                    <div class="input-prepend">
                        <span class="add-on">Expression</span>
                        <input type="text" v-model="new_process[key]" @change="new_process_update">
                    </div>
                </div>
                <div v-if="param.type=='select'">
                    <b v-html="param.short"></b><br>
                    <select v-model="new_process[key]" @change="new_process_update">
                        <option v-for="(option,optionname) in param.options" v-bind:value="optionname">{{option}}</option>
                    </select>
                </div>
            </span>

            <div class="input-prepend input-append">
                <span class="add-on">Process:</span>
                <select v-model="new_process_mode" @change="new_process_update" style="width:150px">
                    <option value="all">from the start</option>
                    <!--<option value="from">from timestamp</option>-->
                    <option value="recent">recent only</option>
                </select>
                <input type="text" v-model="new_process_start" @change="new_process_update" v-if="new_process_mode=='from'" placeholder="timestamp" style="width:100px">
                <button class="btn btn-success" v-if="new_process_create" @click="create_process">Run</button>
            </div>

            <div class="alert alert-error" v-if="new_process_error"><b>Error: </b>{{new_process_error}}</div>
        </div>
    </div>
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
</script>
<script type="text/javascript" src="<?php echo $path; ?>Modules/postprocess/view.js?v=13"></script>
