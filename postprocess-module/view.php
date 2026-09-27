<?php global $path; ?>
<?php
load_js("Lib/js/vue.global.prod-3.5.22.min.js");
load_js("Modules/feed/feed.js");
?>
<?php load_css("Modules/postprocess/view.css"); ?>

<div class="page-header">
    <h3>Post Process</h3>
</div>

<div id="app" class="panel-page postprocess-page" v-cloak>
    <p class="page-lead">Process existing PHPFina feed data into new feeds.</p>

    <div class="panel">
        <div class="panel-header panel-header-static">
            <span class="panel-accent"></span>
            <span class="panel-name">Processes</span>
            <span class="panel-badge">{{ process_list.length }}</span>
        </div>
        <div class="panel-table" v-if="process_list.length">
            <table>
                <colgroup><col class="pp-col-process"><col><col class="pp-col-mode"><col class="pp-col-status"><col class="pp-col-actions"></colgroup>
                <thead>
                    <tr><th>Process</th><th>Parameters</th><th>Mode</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="(item,index) in process_list" :class="{'is-editing': mode=='edit' && selected_process==item.processid}">
                        <td class="col-primary" :title="'Process ID: '+item.processid">{{ processes[item.params.process].name }}</td>
                        <td class="pp-params">
                            <div v-for="(param,key) in processes[item.params.process].settings">
                                <span class="pp-key">{{ key }}</span>
                                <template v-if="param.type=='feed' || param.type=='newfeed'">
                                    <span v-if="feeds_by_id[item.params[key]]!=undefined">{{ feeds_by_id[item.params[key]].name }}</span>
                                    <span v-else class="text-danger">Feed not found</span>
                                    <span class="pp-id">({{ item.params[key] }})</span>
                                </template>
                                <template v-else>{{ item.params[key] }}</template>
                            </div>
                        </td>
                        <td class="col-secondary">
                            <span v-if="item.params.process_mode=='recent'">New data only</span>
                            <span v-if="item.params.process_mode=='all'">Reprocess all</span>
                            <span v-if="item.params.process_mode=='from'">From {{ item.process_start }}</span>
                        </td>
                        <td>
                            <span v-if="item.status=='queued'" :title="time_ago(item.status_updated)" class="badge bg-info">Queued</span>
                            <span v-if="item.status=='running'" :title="time_ago(item.status_updated)" class="badge bg-warning">Running</span>
                            <span v-if="item.status=='finished'" :title="time_ago(item.status_updated)+'\n\n'+item.status_message" class="badge bg-success">Finished</span>
                            <span v-if="item.status=='error'" :title="time_ago(item.status_updated)" class="badge bg-danger">Error</span>
                            <div v-if="item.status=='error'" class="pp-error">{{ item.status_message }}</div>
                        </td>
                        <td class="panel-actions">
                            <button class="btn btn-default btn-sm" @click="run_process(item.processid)"><span class="svg-icon-play"></span> Run</button>
                            <button class="btn btn-default btn-sm" @click="edit_process(index)"><span class="svg-icon-pencil"></span> Edit</button>
                            <button class="btn btn-danger btn-sm" title="Delete" @click="delete_process(item.processid)"><span class="svg-icon-trash"></span></button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="panel-body panel-empty" v-else>
            <p><b>No processes created yet</b></p>
            <p>Rather than process inputs as data arrives in emoncms such as calculating cumulative kWh data from power data with the power to kWh input process, this module can be used to do these kind of processing steps after having recorded base data such as power data for some time. This removes the reliance on setting up everything right in the first instance providing the flexibility to recalculate processed feeds at a later date.</p>
        </div>
    </div>

    <div class="panel" id="pp-form">
        <div class="panel-header panel-header-static">
            <span class="panel-accent"></span>
            <span class="panel-name"><span v-if="mode=='create'">New process</span><span v-if="mode=='edit'">Edit process</span></span>
        </div>
        <div class="panel-body panel-form">
            <div class="panel-field">
                <label class="form-label">Process</label>
                <select class="form-select input-285" v-model="new_process_select" @change="new_process_selected">
                    <option value="none">Select process</option>
                    <optgroup v-for="(group,groupname) in processes_by_group" v-bind:label="groupname">
                        <option v-for="(item,key) in group" :value="key">{{item.name}}</option>
                    </optgroup>
                </select>
                <div class="form-text" v-if="processes[new_process_select]!=undefined" v-html="processes[new_process_select].description"></div>
            </div>

            <template v-if="processes[new_process_select]!=undefined">
                <div class="panel-field" v-for="(param,key) in processes[new_process_select].settings">
                    <label class="form-label" v-html="param.short"></label>

                    <div v-if="param.type=='feed' || param.type=='newfeed'" class="input-group">
                        <select class="form-select input-220" v-model="new_process[key]" @change="change_feed_select">
                            <option value="none" v-if="param.type=='feed'">Select feed</option>
                            <option value="create" v-if="param.type=='newfeed'">Create new</option>
                            <optgroup v-for="(tag,tagname) in feeds_by_tag" v-bind:label="tagname">
                                <template v-for="(feed,feedid) in tag">
                                    <option v-if="feed.engine==5" v-bind:value="feedid">{{feed.name}}</option>
                                </template>
                            </optgroup>
                        </select>
                        <input type="text" v-if="new_process[key]=='create'" v-model="new_feed[key].tag" placeholder="Tag" class="form-control input-105" @change="new_process_update"/>
                        <input type="text" v-if="new_process[key]=='create'" v-model="new_feed[key].name" placeholder="Name" class="form-control input-165" @change="new_process_update" />
                    </div>

                    <input v-if="param.type=='value' || param.type=='timezone'" class="form-control input-220" type="text" v-model="new_process[key]" @change="new_process_update">

                    <template v-if="param.type=='formula'">
                        <div class="input-group">
                            <span class="input-group-text">Feed finder</span>
                            <select class="form-select input-220" v-model="formula_feed_finder_id" @change="formula_feed_finder_change">
                                <option value="none">Select feed</option>
                                <optgroup v-for="(tag,tagname) in feeds_by_tag" v-bind:label="tagname">
                                    <template v-for="(feed,feedid) in tag">
                                        <option v-if="feed.engine==5" v-bind:value="feedid">{{feed.name}}: f{{feed.id}}</option>
                                    </template>
                                </optgroup>
                            </select>
                        </div>
                        <div class="input-group">
                            <span class="input-group-text">Expression</span>
                            <input class="form-control input-285" type="text" v-model="new_process[key]" @change="new_process_update">
                        </div>
                    </template>

                    <select v-if="param.type=='select'" class="form-select input-220" v-model="new_process[key]" @change="new_process_update">
                        <option v-for="(option,optionname) in param.options" v-bind:value="optionname">{{option}}</option>
                    </select>
                </div>

                <div class="panel-field">
                    <label class="form-label">Run on</label>
                    <div class="input-group">
                        <select class="form-select input-220" v-model="new_process_mode" @change="new_process_update">
                            <option value="all">All data, from the start</option>
                            <!--<option value="from">from timestamp</option>-->
                            <option value="recent">New data only</option>
                        </select>
                        <input type="text" v-model="new_process_start" @change="new_process_update" v-if="new_process_mode=='from'" placeholder="timestamp" class="form-control input-105">
                    </div>
                </div>

                <div class="alert alert-danger" v-if="new_process_error"><b>Error: </b>{{new_process_error}}</div>

                <div class="panel-buttons">
                    <button class="btn btn-primary" :disabled="!new_process_create" @click="create_process">
                        <span v-if="mode=='create'">Create and run</span><span v-else>Save and run</span>
                    </button>
                    <button class="btn btn-default" v-if="mode=='edit'" @click="cancel_edit">Cancel</button>
                </div>
            </template>
        </div>
    </div>
</div>

<!--
<hr>
<button id="getlog" type="button" class="btn btn-info" data-bs-toggle="button" aria-pressed="false" autocomplete="off" style="float:right; margin-top:10px"><?php echo _('Auto refresh'); ?></button>
<h3>Logger</h3>
<div id="logpath"></div>
<pre id="logreply-bound" class="log"><div id="logreply"></div></pre>
--->

<script>
    var processes = <?php echo json_encode($processes); ?>;
</script>
<?php load_js("Modules/postprocess/view.js"); ?>