var TaskManager = function() {
	var taskId = 0;

	/*
	 * Private method to get the task id from the UI element
	 * 
	 * @param element task The task element
	 * @return string The task id
	 */
	var getTaskId = function(el) {
		return el.find('input[type=hidden]').attr('value');
	};

	/*
	 * Private method to get the task title from the UI element
	 * 
	 * @param element task The task element
	 * @return string The task title
	 */
	var getTaskTitle = function(el) {
		return el.find('input[type=text]').val();
	};
	
	var doRequest = function(url, success) {
		$.ajax({
			url: url,
			type: 'GET',
			dataType: 'json',
			success: success,
			error: function() {
				debugger;
			}
		});
	};

	return {
		/*
		 * Public method addTask
		 *
		 * Add a new task
		 */
		addTask: function(task) {
			var self = this;
			var tpl = $('#task').clone();
			var el = $(tpl);
			var _create = function(response) {
				el.removeAttr('id');

				// assign a task id
				if (task.id == null) {
					task.id = response.id;
				}
	
				el.find('input[type=hidden]')
					.attr('value', task.id)
				;
	
				el.find('input[name=title]')
					// set task title
					.val(task.title)
					
					// attach the 'update' action to the title field
					.blur($.proxy(function() {
						self.updateTask(this);
					}, el))
				;

				el.find('#addTask')
					// remove id
					.removeAttr('id')
					
					// change button style
					.attr('class', 'button delete')
	
					// attach the 'delete' action to the button
					.click($.proxy(function() {
						self.deleteTask(this);
					}, el))
				;
	
				// add the task to the list
				tpl.appendTo('#tasks');
	
				console.log('add ' + task.id);
			};
			
			if (task.id == null) {
				doRequest('api/task/add?title=' + task.title, _create);
			}
			else {
				_create();
			}
		},

		/*
		 * Public method addTask
		 *
		 * Delete a task
		 */
		deleteTask: function(el) {
			var id = getTaskId(el);

			doRequest('api/task/delete/' + id, function() {
				el.remove();
				console.log('delete ' + id);		
			});
		},

		/*
		 * Populate the task list
		 */
		getTasks: function() {
			var self = this;

			doRequest('api/tasks', function(data) {
				for (var i = 0; i < data.length; i++) {
					self.addTask(data[i]);
				}
			});
		},

		/*
		 * Public method addTask
		 *
		 * Update a task
		 */
		updateTask: function(el) {
			var id = getTaskId(el);
			var title = getTaskTitle(el);

			doRequest('api/task/update/' + id + '?title=' + title, function() {
				alert('Updated!');
				console.log('update ' + id);
			});
		}
	}
};

jQuery(function($) {
	var taskManager = new TaskManager();

	taskManager.getTasks();

	$('#addTask').click(function() {
		var title = $('#task > input[name=title]');

		taskManager.addTask({
			"id": null,
			"title": title.val()
		});

		// reset the text
		title.val('');
	});
});
