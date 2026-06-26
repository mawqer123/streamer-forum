// upload_manager.js - 全局上传管理器（修复任务完成后不删除导致提交阻塞的问题）

(function() {
    window.UploadManager = {
        tasks: new Map(),
        taskCounter: 0,
        onAllCompleteCallbacks: [],
        
        addTask: function(taskId) {
            this.tasks.set(taskId, { completed: false, progress: 0 });
            this.updateSubmitButtonState();
        },
        
        updateProgress: function(taskId, percent) {
            let task = this.tasks.get(taskId);
            if (task) {
                task.progress = percent;
                this.triggerProgressEvent(taskId, percent);
            }
        },
        
        completeTask: function(taskId, result) {
            let task = this.tasks.get(taskId);
            if (task) {
                task.completed = true;
                task.result = result;
                this.triggerTaskComplete(taskId, result);
                // 任务完成后立即删除，避免阻塞提交
                this.tasks.delete(taskId);
                this.checkAllComplete();
            }
        },
        
        removeTask: function(taskId) {
            this.tasks.delete(taskId);
            this.checkAllComplete();
        },
        
        checkAllComplete: function() {
            this.updateSubmitButtonState();
            if (this.tasks.size === 0 && this.onAllCompleteCallbacks.length) {
                this.triggerAllComplete();
            }
        },
        
        updateSubmitButtonState: function() {
            let hasUnfinished = this.tasks.size > 0;
            let submitBtns = document.querySelectorAll('.btn-submit, .comment-submit, .btn-primary[type="submit"]');
            submitBtns.forEach(btn => {
                if (hasUnfinished) {
                    btn.disabled = true;
                    btn.classList.add('uploading');
                    if (!btn.hasAttribute('data-original-text')) {
                        btn.setAttribute('data-original-text', btn.innerHTML);
                    }
                    btn.innerHTML = '上传中...';
                } else {
                    btn.disabled = false;
                    btn.classList.remove('uploading');
                    if (btn.hasAttribute('data-original-text')) {
                        btn.innerHTML = btn.getAttribute('data-original-text');
                    }
                }
            });
        },
        
        onAllComplete: function(callback) {
            this.onAllCompleteCallbacks.push(callback);
        },
        
        triggerAllComplete: function() {
            this.onAllCompleteCallbacks.forEach(cb => cb());
        },
        
        triggerProgressEvent: function(taskId, percent) {
            let event = new CustomEvent('uploadProgress', { detail: { taskId, percent } });
            document.dispatchEvent(event);
        },
        
        triggerTaskComplete: function(taskId, result) {
            let event = new CustomEvent('uploadComplete', { detail: { taskId, result } });
            document.dispatchEvent(event);
        }
    };
    
    window.uploadFile = function(file, url, formDataCallback, onProgress, onSuccess, onError) {
        let taskId = 'upload_' + (++UploadManager.taskCounter);
        UploadManager.addTask(taskId);
        
        let xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        
        if (xhr.upload) {
            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    let percent = Math.round((e.loaded / e.total) * 100);
                    UploadManager.updateProgress(taskId, percent);
                    if (onProgress) onProgress(percent);
                }
            };
        }
        
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    let response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        UploadManager.completeTask(taskId, response);
                        if (onSuccess) onSuccess(response);
                    } else {
                        UploadManager.removeTask(taskId);
                        if (onError) onError(response.message || '上传失败');
                    }
                } catch(e) {
                    UploadManager.removeTask(taskId);
                    if (onError) onError('解析响应失败');
                }
            } else {
                UploadManager.removeTask(taskId);
                if (onError) onError('服务器错误: ' + xhr.status);
            }
        };
        
        xhr.onerror = function() {
            UploadManager.removeTask(taskId);
            if (onError) onError('网络错误');
        };
        
        let formData = new FormData();
        if (formDataCallback) {
            formDataCallback(formData);
        } else {
            formData.append('file', file);
        }
        
        xhr.send(formData);
        return taskId;
    };
})();