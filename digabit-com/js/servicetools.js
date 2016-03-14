var digabit = (function() {
	'use strict';

	var _cache = {};
	var _fileId = 0;
	var _folderId = 0;
	var _migrationId = 0;

	return {
		filterHilight: function(haystack, needle) {
			if (needle !== '') {
				haystack = haystack.replace(this.getFilterRegex(needle), '<span class="filter-hilight">$1</span>');
			}

			return haystack;
		},
		filterMask: function(haystack, needle) {
			var mask;

			if ( typeof needle === 'boolean') {
				mask = needle;
			}
			else
			if (needle !== '') {
				mask = !haystack.match(this.getFilterRegex(needle));
			}

			if (mask) {
				haystack = '<span class="filter-masked">{1}</span>'.apply(haystack);
			}

			return haystack;
		},
		getCachedItem: function(id, def) {
			return _cache[id] || def;
		},

		// get list of files that contain (or not) a path:
		// 1 = conforming
		// 0 = non-conforming
		getFileUrl: function(conforms) {
			var url = '/api/migration/{1}/folder/{2}/files'.apply(_migrationId, _folderId);
			var hash = _cache['pathhash'] || '';
			var filter = _cache['filefilter'] || '';

			if (hash !== '') {
				url += '?hash={1}&conforms={2}'.apply(hash, conforms);
			}

			if (filter !== '') {
				url += '&filter={1}'.apply(filter);
			}

			return url;
		},
		getFilterRegex: function(filter/*string*/) {
			filter = filter.replace(/[\-\[\]\/\{\}\(\)\*\+\?\.\\\^\$\|]/g, "\\$&");

			return new RegExp('(' + filter + ')', 'gi');
		},
		getFolderId: function() {
			return _folderId;
		},
		getFolderUrl: function() {
			var url = '/api/migration/{1}/folders?tree'.apply(_migrationId);
			var filter = _cache['folderfilter'] || '';

			if (filter !== '') {
				url += '&filter={1}&expanded'.apply(filter);
			}

			return url;
		},
		getMigrationId: function() {
			return _migrationId;
		},
		getMigrationsUrl: function() {
			return '/api/migrations';
		},
		getPathUrl: function() {
			var url = '/api/migration/{1}/folder/{2}/paths?tree'.apply(_migrationId, _folderId);
			var filter = _cache['pathfilter'] || '';
			var conforms = _cache['pathconforms'] || [0, 100];

			if (filter || conforms[0] > 0 || conforms[1] < 100) {
				if (filter) {
					url += '&filter=' + filter;
				}
				url += '&minconforms={1}&maxconforms={2}'.apply(conforms[0], conforms[1]);
				url += '&expanded';
			}

			return url;
		},
		getXpathViewUrl: function() {
			var url = '/api/migration/{1}/file/{2}/paths'.apply(_migrationId, _fileId);
			var filter = _cache['pathhash'] || '';

			if (filter !== '') {
				url += '?filter=' + filter;
			}

			return url;
		},
		getViewUrl: function() {
			return '/api/migration/{1}/file/{2}/view'.apply(_migrationId, _fileId);
		},
		loadStore: function(store, url) {
			if (store.loading && store._lastOperation) {
				var requests = Ext.Ajax.requests;

				try {
					for (id in requests) {
						if (requests.hasOwnProperty(id) && requests[id].options == store._lastOperation.request) {
							Ext.Ajax.abort(requests[id]);
						}
					}
				}
				catch(e) {
				}
			}

			store.on('beforeload', function(store, operation) {
				store._lastOperation = operation;
			}, this, {
				single: true
			});

			store.load({
				url: url,
				params: {
					start: 0
				}
			});

			//store.loadPage(1);
		},
		matchConforms: function(value) {
			var range = this.getCachedItem('pathconforms', [0, 100]);

			if (range[0] == 0 && range[1] == 100) {
				return -1;
			}

			return value.within(range[0], range[1]) ? 1 : 0;
		},
		resetFileFilters: function() {
			Ext.getCmp('fileFilter').setValue('');
		},
		resetFolderFilters: function() {
			Ext.getCmp('folderFilter').setValue('');
		},
		resetPathFilters: function() {
			Ext.getCmp('pathFilter').setValue('');
			Ext.getCmp('pathConforms').setValue(0, 0);
			Ext.getCmp('pathConforms').setValue(1, 100);
		},
		setCachedItem: function(id, value) {
			_cache[id] = value;

			return value;
		},
		setFileId: function(fileId) {
			_fileId = fileId;

			return fileId;
		},
		setFolderId: function(folderId) {
			_folderId = folderId;

			return folderId;
		},
		setMigrationId: function(migrationId) {
			_migrationId = migrationId;

			return migrationId;
		},
		updateXpathView: function(content) {
			var view = Ext.getCmp('xpathView');
			var markup;
			var output = '<table class="dataview">';

			for (var i = 0; i < content.length; i++) {
				markup = vkbeautify.xml(content[i]);
				markup = markup.replace(/</g, '&lt;').replace(/>/g, '&gt;');
				markup = markup.replace(/\t/g, '    ');
				markup = '<pre><code class="xml">' + markup + '</code></pre>';

				output += '<tr><td>{1}</td><td>{2}</td><tr>'.apply(Ext.util.Format.number(i + 1, '000,000'), markup);
			}

			output += '</table>';

			view.body.update(output);
			hljs.highlightBlock(view.body.el.dom);
		}
	};
})();

Ext.Loader.setPath('Ext.ux', '../../3rdparty/ext-4.2.1.883/examples/ux');
Ext.require(['Ext.data.*', 'Ext.grid.*', 'Ext.ProgressBar', 'Ext.tree.*', 'Ext.toolbar.Paging', 'Ext.ux.RowExpander', 'Ext.selection.CheckboxModel']);

Ext.onReady(function() {
	Ext.define('File', {
		extend: 'Ext.data.Model',
		fields: [{
			name: 'file_id',
			type: 'number'
		}, {
			name: 'path',
			type: 'string'
		}, {
			name: 'file',
			type: 'string'
		}, {
			name: 'cardinality',
			type: 'number'
		}, {
			name: 'exists',
			type: 'number'
		}]
	});

	Ext.define('Folder', {
		extend: 'Ext.data.Model',
		fields: [{
			name: 'folder_id',
			type: 'number'
		}, {
			name: 'name',
			type: 'string'
		}, {
			name: 'path',
			type: 'string'
		}, {
			name: 'files',
			type: 'number'
		}, {
			name: 'progress',
			type: 'number'
		}]
	});

	Ext.define('Migration', {
		extend: 'Ext.data.Model',
		fields: [{
			name: 'migration_id',
			type: 'number'
		}, {
			name: 'name',
			type: 'string'
		}, {
			name: 'type',
			type: 'string'
		}, {
			name: 'data_path',
			type: 'string'
		}, {
			name: 'file_type',
			type: 'string'
		}, {
			name: 'folders',
			type: 'number'
		}, {
			name: 'files',
			type: 'number'
		}, {
			name: 'stage',
			type: 'number'
		}, {
			name: 'progress',
			type: 'number'
		}]
	});

	Ext.define('Path', {
		extend: 'Ext.data.Model',
		fields: [{
			name: 'path',
			type: 'string'
		}, {
			name: 'display',
			type: 'string'
		}, {
			name: 'found',
			type: 'number'
		}, {
			name: 'missing',
			type: 'number'
		}, {
			name: 'conforms',
			type: 'number'
		}, {
			name: 'hash',
			type: 'string'
		}]
	});

	var folderStore = Ext.create('Ext.data.TreeStore', {
		model: 'Folder',
		proxy: {
			type: 'ajax',
			url: ''
		},
		autoLoad: false
	});

	var migrationStore = Ext.create('Ext.data.Store', {
		model: 'Migration',
		proxy: {
			type: 'ajax',
			url: digabit.getMigrationsUrl()
		},
		autoLoad: true
	});

	var pathStore = Ext.create('Ext.data.TreeStore', {
		model: 'Path',
		proxy: {
			type: 'ajax',
			url: ''
		},
		autoLoad: false,
		listeners: {
			load: function(store) {
				Ext.getCmp('pathCount').setText(Ext.util.Format.number(store.tree.flatten().length - 1, '000,000') + " Unique Paths");
			}
		}
	});

	var fileTopBar = Ext.create('Ext.toolbar.Toolbar', {
		border: 0,
		items: [{
			id: 'fileFilter',
			xtype: 'textfield',
			emptyText: "file filter",
			listeners: {
				change: function(cmp, value) {
					digabit.setCachedItem('filefilter', value);
				}
			}
		}, {
			xtype: 'button',
			text: "Filter",
			listeners: {
				click: function() {
					digabit.loadStore(fileStore[0], digabit.getFileUrl(1));
				}
			}
		}, {
			xtype: 'button',
			text: "Reset",
			listeners: {
				click: function() {
					digabit.resetFileFilters();
					digabit.loadStore(fileStore[0], digabit.getFileUrl(1));
				}
			}
		}, '->', {
			xtype: 'button',
			text: "Refresh",
			listeners: {
				click: function() {
					digabit.loadStore(fileStore[0], digabit.getFileUrl(1));
				}
			}
		}]
	});

	var fileStore = [];
	var fileGrid = [];

	var dataViewTypes = [{
		id: 'xpathView',
		url: digabit.getXpathViewUrl,
		update: digabit.updateXpathView,
		title: "XPath"
	}];

	var fileViewTypes = [{
		type: 1,
		column: 'found',
		title: "Conforming"
	}, {
		type: 0,
		column: 'missing',
		title: "Non-conforming"
	}];
	var fileGridTabs = Ext.create('Ext.tab.Panel', {
		border: 0,
		tabPosition: 'bottom'
	});

	var fileColumns = [{
		text: "File",
		minWidth: 200,
		width: '50%',
		autoSizeColumn: true,
		sortable: true,
		dataIndex: 'file',
		renderer: function(value, metaData, record) {
			// indicate a missing file
			if (!record.data.exists) {
				metaData.css += ' analysis-bad';
			}
			return value;
		}
	}, {
		text: "Paths",
		minWidth: 100,
		autoSizeColumn: true,
		align: 'right',
		sortable: true,
		flex: 1,
		dataIndex: 'cardinality',
		renderer: function(value) {
			return Ext.util.Format.number(value, '000,000');
		}
	}];

	for (var i = 0; i <= 1; i++) {
		fileStore[i] = Ext.create('Ext.data.Store', {
			model: 'File',
			proxy: {
				type: 'ajax',
				url: ''
			},
			autoLoad: false,
			listeners: {
				// get latest url before passing it to paging proxy
				beforeload: function(store) {
					var index = [].slice.call(arguments).pop();

					store.getProxy().url = digabit.getFileUrl(fileViewTypes[index].type);
				}.bindAppend(this, i),

				// update paging with correct file count
				load: function(store) {
					var index = [].slice.call(arguments).pop();
					var pager = Ext.getCmp('filePager_' + index);

					store.totalCount = digabit.getCachedItem('filecount_' + index, 0);
					pager.onLoad();
//debugger;
//pager.moveFirst();
				}.bindAppend(this, i)
			}
		});

		fileGrid[i] = Ext.create('Ext.grid.Panel', {
			border: 0,
			store: fileStore[i],
			bbar: Ext.create('Ext.PagingToolbar', {
				id: 'filePager_' + i,
				border: 0,
				displayInfo: true,
				displayMsg: "{0} - {1} of {2} Files",
				store: fileStore[i]
			}),
			columns: i == 0 ? [fileColumns[0], fileColumns[1]] : [fileColumns[0]],
			plugins: [{
				ptype: 'rowexpander',
				rowBodyTpl: ['<b>File path:</b> <span class="x-selectable">{path}/{file} </span>']
			}],
			viewConfig: {
				emptyText: 'No files found'
			},
			listeners: {
				beforeload: function() {
					if (this.rendered) {
						this.getEl().mask("Loading...");
					}
				},
				load: function(store) {
					if (this.rendered) {
						this.getEl().unmask();
					}
				},
				select: function(grid, row) {
					digabit.setFileId(row.data['file_id']);

					for (var i = 0; i < dataViewTypes.length; i++) {
						var update = dataViewTypes[i].update;

						Ext.Ajax.request({
							url: (dataViewTypes[i].url)(),
							success: function(response, request, fnUpdate) {
								fnUpdate(Ext.JSON.decode(response.responseText));
							}.bindAppend(this, update),
							failure: function(response, request, fnUpdate) {
								fnUpdate(["Failed to parse file!"]);
							}.bindAppend(this, update)
						});
					}
				}
			}
		});
		
		fileGridTabs.add({
			title: fileViewTypes[i].title,
			layout: 'fit',
			border: 0,
			items: [fileGrid[i]]
		});
	}

	var dataViewTabs = Ext.create('Ext.tab.Panel', {
		border: 0,
		tabPosition: 'bottom'
	});

	for (var i = 0; i < dataViewTypes.length; i++) {
		dataViewTabs.add(Ext.create('Ext.panel.Panel', {
			title: dataViewTypes[i].title,
			id: dataViewTypes[i].id,
			layout: 'fit',
			border: 0,
			autoScroll: true
		}));
	}

	// set active tabs
	fileGridTabs.setActiveTab(fileGridTabs.items.items[0]);
	dataViewTabs.setActiveTab(dataViewTabs.items.items[0]);

	var folderTree = Ext.create('Ext.tree.Panel', {
		id: 'folderTree',
		border: 0,
		useArrows: false,
		rootVisible: false,
		store: folderStore,
		multiSelect: false,
		singleExpand: false,
		columns: [{
			xtype: 'treecolumn',
			text: "Folder",
			minWidth: 500,
			autoSizeColumn: true,
			sortable: true,
			dataIndex: 'name',
			renderer: function(value) {
				var filter = digabit.getCachedItem('folderfilter', '');
				var value = digabit.filterHilight(value, filter);
				value = digabit.filterMask(value, filter);
				return value;
			}
		}, {
			text: "Files",
			autoSizeColumn: true,
			sortable: true,
			dataIndex: 'files',
			align: 'right',
			renderer: function(value) {
				return value > 0 ? Ext.util.Format.number(value, '000,000') : '';
			}
		}],
		viewConfig: {
			emptyText: 'No folders found'
		},
		listeners: {
			beforeload: function() {
				if (this.rendered) {
					this.getEl().mask("Loading...");
				}
			},
			load: function(store) {
				if (this.rendered) {
					this.getEl().unmask();
					Ext.getCmp('folderCount').setText(Ext.util.Format.number(store.tree.flatten().length - 1, '000,000') + " Folders");
				}
			},
			select: function(grid, row) {
				digabit.setFolderId(row.data['folder_id']);
				digabit.loadStore(pathStore, digabit.getPathUrl());
			}
		}
	});

	var folderBottomBar = Ext.create('Ext.toolbar.Toolbar', {
		border: 0,
		items: [{
			id: 'folderCount',
			xtype: 'tbtext',
			text: "0 Folders"
		}]
	});

	var folderTopBar = Ext.create('Ext.toolbar.Toolbar', {
		border: 0,
		items: [{
			id: 'folderFilter',
			xtype: 'textfield',
			emptyText: "folder filter",
			listeners: {
				change: function(cmp, value) {
					digabit.setCachedItem('folderfilter', value);
				}
			}
		}, {
			xtype: 'button',
			text: "Filter",
			listeners: {
				click: function() {
					digabit.loadStore(folderStore, digabit.getFolderUrl());
				}
			}
		}, {
			xtype: 'button',
			text: "Reset",
			listeners: {
				click: function() {
					digabit.resetFolderFilters();
					digabit.loadStore(folderStore, digabit.getFolderUrl());
				}
			}
		}, '->', {
			xtype: 'button',
			text: "Refresh",
			listeners: {
				click: function() {
					digabit.loadStore(folderStore, digabit.getFolderUrl());
				}
			}
		}]
	});

	var migrationBottomBar = Ext.create('Ext.toolbar.Toolbar', {
		border: 0,
		items: [{
			id: 'migrationCount',
			xtype: 'tbtext',
			text: "0 Migrations"
		}]
	});

	var migrationTopBar = Ext.create('Ext.toolbar.Toolbar', {
		border: 0,
		items: ['->', {
			xtype: 'button',
			text: "Refresh",
			listeners: {
				click: function() {
					digabit.loadStore(migrationStore, digabit.getMigrationsUrl());
				}
			}
		}]
	});

	var migrationGrid = Ext.create('Ext.grid.Panel', {
		border: 0,
		store: migrationStore,
		columns: [{
			text: "Name",
			width: 100,
			sortable: true,
			dataIndex: 'name'
		}, {
			text: "Type",
			width: 40,
			sortable: true,
			dataIndex: 'type'
		}, {
			text: "Folders",
			width: 70,
			sortable: true,
			dataIndex: 'folders',
			align: 'right',
			renderer: function(value) {
				return Ext.util.Format.number(value, '000,000');
			}
		}, {
			text: "Files",
			width: 70,
			sortable: true,
			dataIndex: 'files',
			align: 'right',
			renderer: function(value) {
				return Ext.util.Format.number(value, '000,000');
			}
		}, {
			text: "Progress",
			flex: 1,
			sortable: true,
			dataIndex: 'progress',
			renderer: function(value, row) {
				var text = Ext.util.Format.number(value, "00.00%");

				switch (row.record.data.stage) {
					case 0:
						text = "Waiting...";
						break;
					case 1:
						text = "Finding folders...";
						break;
					case 2:
						text = "Reading files ({1})".apply(text);
						break;
					case 3:
						text = "Parsing data ({1})".apply(text);
						break;
					default:
						text = "Complete";
						break;
				}

				return text;
			}
		}],
		plugins: [{
			ptype: 'rowexpander',
			rowBodyTpl: ['']
		}],
		viewConfig: {
			emptyText: 'No migrations found',
			listeners: {
				expandbody: function(row, record, expandRow, eOpts) {
					var data = record.data;
					var stage = ["Defined", "Found folders", "Found files", "Parsed data", "Complete"];
					var html = ['<b>Data path:</b> <span class="x-selectable">{1} </span>'.apply(data.data_path), "<b>File types:</b> {1}".apply(data.file_type)];

					for (var i = 1; i < 4; i++) {
						html.push("<b>{1}</b>: {2}".apply(stage[i], data.stage > i ? '<div class="check"></div>' : Ext.util.Format.number(data.progress, "00.00%")));
					}
					Ext.get(expandRow).setHTML('<td class="rowexpander" colspan="5">' + html.join('<br/>') + '</td>');
				}
			}
		},
		listeners: {
			viewready: function() {
				Ext.getCmp('migrationCount').setText(Ext.util.Format.number(this.store.data.length, "000,000") + " Migrations");
			},
			select: function(grid, row) {
				// cache row id
				digabit.setMigrationId(row.data['migration_id']);

				// reset filters
				digabit.resetFolderFilters();
				digabit.resetPathFilters();

				// load folders grid
				digabit.loadStore(folderStore, digabit.getFolderUrl());
			}
		},
	});

	var migrationPlan = Ext.create('Ext.form.Panel', {
		border: 0
	});

	var pathBottomBar = Ext.create('Ext.toolbar.Toolbar', {
		border: 0,
		items: [{
			id: 'pathCount',
			xtype: 'tbtext',
			text: "0 Unique Paths"
		}]
	});

	var pathTopBar = Ext.create('Ext.toolbar.Toolbar', {
		border: 0,
		items: [{
			id: 'pathFilter',
			xtype: 'textfield',
			emptyText: "path filter",
			listeners: {
				change: function(cmp, value) {
					digabit.setCachedItem('pathfilter', value);
				}
			}
		}, {
			id: 'pathConforms',
			xtype: 'slider',
			hideLabel: false,
			width: 100,
			values: [0, 100],
			increment: 1,
			minValue: 0,
			maxValue: 100,
			tipText: function(thumb) {
				return thumb.value + "%";
			},
			listeners: {
				change: function(cmp, value, thumb) {
					digabit.setCachedItem('pathconforms', cmp.getValues());
				}
			}
		}, {
			xtype: 'button',
			text: "Filter",
			listeners: {
				click: function() {
					digabit.loadStore(pathStore, digabit.getPathUrl());
				}
			}
		}, {
			xtype: 'button',
			text: "Reset",
			listeners: {
				click: function() {
					digabit.resetPathFilters();
					digabit.loadStore(pathStore, digabit.getPathUrl());
				}
			}
		}, '->', {
			xtype: 'button',
			text: "Refresh",
			listeners: {
				click: function() {
					digabit.loadStore(pathStore, digabit.getPathUrl());
				}
			}
		}]
	});

	var pathTree = Ext.create('Ext.tree.Panel', {
		id: 'pathTree',
		border: 0,
		useArrows: false,
		rootVisible: false,
		store: pathStore,
		multiSelect: false,
		singleExpand: false,
		columns: [{
			xtype: 'treecolumn',
			text: "Unique Path",
			minWidth: 500,
			autoSizeColumn: true,
			sortable: true,
			dataIndex: 'display',
			renderer: function(value, metadata) {
				var filter = digabit.getCachedItem('pathfilter', '');
				value = digabit.filterHilight(value, filter);

				value = digabit.filterMask(value, filter);
				value = digabit.filterMask(value, digabit.matchConforms(metadata.record.data.conforms) === 0);

				value = value.replace(/(\w+:)/g, '<span class="hljs-keyword">$1</span>');
				value = value.replace(/(\[.*\]$)/g, '<span class="hljs-attribute">$1</span>');

				return value;
			}
		}, {
			text: "Files",
			minWidth: 100,
			autoSizeColumn: true,
			sortable: true,
			dataIndex: 'found',
			align: 'right',
			renderer: function(value, metadata) {
				value = Ext.util.Format.number(value, "000,000");
				value = digabit.filterMask(value, digabit.matchConforms(metadata.record.data.conforms) === 0);

				return value;
			}
		}, {
			text: "Conforms",
			autoSizeColumn: true,
			minWidth: 150,
			dataIndex: 'conforms',
			align: 'right',
			sortable: true,
			renderer: function(value, metadata) {
				var fn = Ext.util.Format.number;
				var fmt = value == 100 ? "000%" : "00.00%";

				value = '<span class="analysis-good">{1}</span>'.apply(fn(value, fmt)) + (value < 100 ? ' <span class="analysis-bad">({1})</span>'.apply(fn(100 - value, fmt)) : '');
				value = digabit.filterMask(value, digabit.matchConforms(metadata.record.data.conforms) === 0);

				return value;
			}
		}],
		viewConfig: {
			emptyText: "No paths found"
		},
		listeners: {
			beforeload: function() {
				if (this.rendered) {
					this.getEl().mask("Loading...");
				}
			},
			load: function(store) {
				if (this.rendered) {
					this.getEl().unmask();
				}
			},
			select: function(grid, row) {
				digabit.setCachedItem('pathhash', row.data['hash']);

				// needed for file paging
				for (var i = 0; i <= 1; i++) {
					digabit.setCachedItem('filecount_' + i, row.data[fileViewTypes[i].column]);
					digabit.loadStore(fileStore[i], digabit.getFileUrl(fileViewTypes[i].type));
				}

				Ext.getCmp('filesRegion').expand();
			}
		}
	});

	Ext.create('Ext.Viewport', {
		layout: 'border',
		border: 0,
		renderTo: Ext.getBody(),
		items: [{
			id: 'migrationsRegion',
			title: "Migrations",
			region: 'west',
			layout: 'border',
			border: 0,
			width: '33%',
			split: true,
			collapsible: true,
			items: [{
				region: 'center',
				layout: 'fit',
				border: 0,
				tbar: migrationTopBar,
				bbar: migrationBottomBar,
				items: [migrationGrid]
			}, {
				title: "Migration Plan",
				region: 'south',
				layout: 'fit',
				border: 0,
				height: '50%',
				split: true,
				collapsible: true,
				collapsed: true,
				items: [migrationPlan]
			}],
			listeners: {
				collapse: function() {
					Ext.getCmp('filesRegion').expand();
				},
				expand: function() {
					Ext.getCmp('filesRegion').collapse();
				}
			}
		}, {
			region: 'center',
			layout: 'border',
			border: 0,
			items: [{
				title: "Folders",
				region: 'north',
				layout: 'fit',
				border: 0,
				split: true,
				collapsible: true,
				collapsed: false,
				tbar: folderTopBar,
				bbar: folderBottomBar,
				height: '50%',
				items: [folderTree]
			}, {
				id: 'pathsRegion',
				title: "Paths",
				region: 'center',
				layout: 'fit',
				border: 0,
				tbar: pathTopBar,
				bbar: pathBottomBar,
				items: [pathTree]
			}]
		}, {
			id: 'filesRegion',
			title: "Files",
			region: 'east',
			layout: 'border',
			border: 0,
			width: '50%',
			split: true,
			collapsible: true,
			collapsed: true,
			tbar: fileTopBar,
			items: [{
				region: 'center',
				layout: 'fit',
				border: 0,
				items: [fileGridTabs]
			}, {
				title: "Data View",
				region: 'south',
				layout: 'fit',
				border: 0,
				split: true,
				height: '50%',
				items: [dataViewTabs]
			}],
			listeners: {
				expand: function() {
					Ext.getCmp('migrationsRegion').collapse();
				}
			}
		}]
	});
});
