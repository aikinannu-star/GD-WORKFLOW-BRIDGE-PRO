// Live dashboard functionality with polling
(function($) {
    'use strict';

    window.GDWB_Dashboard = {
        projectId: null,
        restUrl: null,
        pollInterval: 5000,
        pollTimers: {},

        init: function(projectId, restUrl) {
            this.projectId = projectId;
            this.restUrl = restUrl;
            this.setupEventListeners();
            this.startPolling();
        },

        setupEventListeners: function() {
            // Chat form
            $(document).on('submit', '#chat-form', (e) => this.handleChatSubmit(e));
            
            // Revision request form
            $(document).on('submit', '#revision-request-form', (e) => this.handleRevisionSubmit(e));
            
            // Requirements form
            $(document).on('submit', '#requirements-form', (e) => this.handleRequirementsSubmit(e));
            
            // Files vault upload
            $(document).on('dragover', '.vault-upload-area', (e) => this.handleDragOver(e));
            $(document).on('dragleave', '.vault-upload-area', (e) => this.handleDragLeave(e));
            $(document).on('drop', '.vault-upload-area', (e) => this.handleFilesDrop(e));
            $(document).on('click', '.vault-upload-area', () => $('[name="vault-file-input"]').click());
            $(document).on('change', '[name="vault-file-input"]', (e) => this.handleFilesSelect(e));

            // Delete file
            $(document).on('click', '.delete-vault-file', (e) => this.handleDeleteFile(e));
        },

        startPolling: function() {
            this.pollMessages();
            this.pollFiles();
            this.pollNotifications();
        },

        pollMessages: function() {
            $.ajax({
                url: this.restUrl + 'chat/' + this.projectId + '/messages',
                type: 'GET',
                headers: { 'X-WP-Nonce': gdwb_dashboard.nonce },
                success: (response) => {
                    this.renderMessages(response);
                    this.pollTimers.messages = setTimeout(() => this.pollMessages(), this.pollInterval);
                }
            });
        },

        pollFiles: function() {
            $.ajax({
                url: this.restUrl + 'vault/' + this.projectId + '/files',
                type: 'GET',
                headers: { 'X-WP-Nonce': gdwb_dashboard.nonce },
                success: (response) => {
                    this.renderFiles(response);
                    this.pollTimers.files = setTimeout(() => this.pollFiles(), this.pollInterval);
                }
            });
        },

        pollNotifications: function() {
            $.ajax({
                url: this.restUrl + 'notifications?limit=10',
                type: 'GET',
                headers: { 'X-WP-Nonce': gdwb_dashboard.nonce },
                success: (response) => {
                    this.renderNotifications(response);
                    this.pollTimers.notifications = setTimeout(() => this.pollNotifications(), 3000);
                }
            });
        },

        handleChatSubmit: function(e) {
            e.preventDefault();
            const message = $('#chat-form textarea[name="message"]').val();
            const isPrivate = $('#chat-form input[name="is_private"]').is(':checked');

            $.ajax({
                url: this.restUrl + 'chat/' + this.projectId + '/send',
                type: 'POST',
                data: JSON.stringify({ message, is_private: isPrivate }),
                contentType: 'application/json',
                headers: { 'X-WP-Nonce': gdwb_dashboard.nonce },
                success: () => {
                    $('#chat-form textarea[name="message"]').val('');
                    this.pollMessages();
                },
                error: (xhr) => {
                    alert('Error sending message: ' + (xhr.responseJSON?.message || 'Unknown error'));
                }
            });
        },

        handleRevisionSubmit: function(e) {
            e.preventDefault();
            const title = $('#revision-request-form input[name="title"]').val();
            const description = $('#revision-request-form textarea[name="description"]').val();
            const priority = $('#revision-request-form select[name="priority"]').val();

            $.ajax({
                url: this.restUrl + 'forms/' + this.projectId + '/revision-request',
                type: 'POST',
                data: JSON.stringify({ title, description, priority }),
                contentType: 'application/json',
                headers: { 'X-WP-Nonce': gdwb_dashboard.nonce },
                success: (response) => {
                    this.showMessage('#revision-request-form .message', response.message, 'success');
                    $('#revision-request-form')[0].reset();
                },
                error: (xhr) => {
                    this.showMessage('#revision-request-form .message', xhr.responseJSON?.message || 'Error', 'error');
                }
            });
        },

        handleRequirementsSubmit: function(e) {
            e.preventDefault();
            const requirements = $('#requirements-form textarea[name="requirements"]').val();
            const deadline = $('#requirements-form input[name="deadline"]').val();

            $.ajax({
                url: this.restUrl + 'forms/' + this.projectId + '/requirements',
                type: 'POST',
                data: JSON.stringify({ requirements, deadline }),
                contentType: 'application/json',
                headers: { 'X-WP-Nonce': gdwb_dashboard.nonce },
                success: (response) => {
                    this.showMessage('#requirements-form .message', response.message, 'success');
                    $('#requirements-form')[0].reset();
                },
                error: (xhr) => {
                    this.showMessage('#requirements-form .message', xhr.responseJSON?.message || 'Error', 'error');
                }
            });
        },

        handleDragOver: function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(e.target).closest('.vault-upload-area').addClass('active');
        },

        handleDragLeave: function(e) {
            e.preventDefault();
            $(e.target).closest('.vault-upload-area').removeClass('active');
        },

        handleFilesDrop: function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(e.target).closest('.vault-upload-area').removeClass('active');
            
            const files = e.originalEvent.dataTransfer.files;
            this.uploadFiles(files);
        },

        handleFilesSelect: function(e) {
            const files = e.target.files;
            this.uploadFiles(files);
        },

        uploadFiles: function(files) {
            $.each(files, (idx, file) => {
                const formData = new FormData();
                formData.append('file', file);
                formData.append('project_id', this.projectId);

                $.ajax({
                    url: this.restUrl + 'vault/' + this.projectId + '/upload',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    headers: { 'X-WP-Nonce': gdwb_dashboard.nonce },
                    success: () => this.pollFiles(),
                    error: (xhr) => {
                        alert('Error uploading ' + file.name + ': ' + (xhr.responseJSON?.message || 'Unknown error'));
                    }
                });
            });
        },

        handleDeleteFile: function(e) {
            if (!confirm('Are you sure you want to delete this file?')) {
                return;
            }

            const fileId = $(e.target).data('file-id');
            $.ajax({
                url: this.restUrl + 'vault/' + fileId + '/delete',
                type: 'DELETE',
                headers: { 'X-WP-Nonce': gdwb_dashboard.nonce },
                success: () => this.pollFiles(),
                error: () => alert('Error deleting file')
            });
        },

        renderMessages: function(messages) {
            const container = $('.gdwb-chat-messages');
            const existing = container.find('.chat-message').map(function() {
                return parseInt($(this).data('id'));
            }).get();

            messages.forEach(msg => {
                if (!existing.includes(msg.id)) {
                    const className = msg.is_private ? 'chat-message private' : 'chat-message';
                    const html = `
                        <div class="${className}" data-id="${msg.id}">
                            <div class="chat-message-author">${msg.author}</div>
                            <div class="chat-message-text">${msg.message}</div>
                            <div class="chat-message-time">${msg.created}</div>
                        </div>
                    `;
                    container.append(html);
                }
            });
            container.scrollTop(container[0].scrollHeight);
        },

        renderFiles: function(files) {
            const container = $('.vault-file-list');
            container.empty();

            if (files.length === 0) {
                container.append('<p>' + gdwb_dashboard.i18n.no_files + '</p>');
                return;
            }

            files.forEach(file => {
                const sizeKB = (file.size / 1024).toFixed(2);
                const html = `
                    <div class="vault-file-item">
                        <div class="vault-file-info">
                            <div class="vault-file-name">${file.name}</div>
                            <div class="vault-file-meta">${file.uploader} • ${sizeKB} KB • ${file.created}</div>
                        </div>
                        <div class="vault-file-actions">
                            <button class="delete-vault-file" data-file-id="${file.id}">Delete</button>
                        </div>
                    </div>
                `;
                container.append(html);
            });
        },

        renderNotifications: function(notifications) {
            const container = $('.gdwb-notifications-panel .notifications-list');
            const existingIds = container.find('.notification-item').map(function() {
                return $(this).data('id');
            }).get();

            notifications.forEach(notif => {
                if (!existingIds.includes(notif.id)) {
                    const html = `
                        <div class="notification-item unread" data-id="${notif.id}">
                            <div class="notification-type">${notif.type}</div>
                            <div class="notification-message">${notif.message}</div>
                            <div class="notification-time">${notif.created}</div>
                        </div>
                    `;
                    container.prepend(html);
                }
            });
        },

        showMessage: function(selector, message, type) {
            const msgEl = $(selector);
            msgEl.removeClass('success error').addClass(type).text(message).show();
            setTimeout(() => msgEl.fadeOut(), 3000);
        }
    };

    $(document).ready(function() {
        if (typeof gdwb_dashboard !== 'undefined' && gdwb_dashboard.project_id) {
            GDWB_Dashboard.init(gdwb_dashboard.project_id, gdwb_dashboard.rest_url);
        }
    });

})(jQuery);
