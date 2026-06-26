<style>
@keyframes page-in {
    0%   { transform: translateX(30px); opacity: 0; }
    100% { transform: translateX(0);    opacity: 1; }
}
#page-content.slide-in {
    animation: page-in 0.22s cubic-bezier(0.22, 1, 0.36, 1);
}
</style>
<!-- 内部刷新导航 JS -->
<script>
// 存储页面级别的定时器，用于在切换时清理
window.__ocPageIntervals = [];

// 注册页面定时器（供各页面脚本调用）
function ocRegisterInterval(id) {
    window.__ocPageIntervals.push(id);
    return id;
}

// 清理所有页面定时器
function ocClearPageIntervals() {
    window.__ocPageIntervals.forEach(function(id) { clearInterval(id); });
    window.__ocPageIntervals = [];
}

// 执行新内容中的脚本标签
function ocExecuteScripts(container) {
    var scripts = container.querySelectorAll('script');
    scripts.forEach(function(oldScript) {
        // 跳过非 JavaScript 脚本（如 type=application/json 的数据脚本）
        var type = oldScript.type || '';
        if (type && type !== 'text/javascript' && type !== 'module' && type !== '') {
            return;
        }
        var newScript = document.createElement('script');
        // 复制所有属性（id、type、class等）
        for (var i = 0; i < oldScript.attributes.length; i++) {
            var attr = oldScript.attributes[i];
            if (attr.name !== 'src') {
                newScript.setAttribute(attr.name, attr.value);
            }
        }
        if (oldScript.src) {
            newScript.src = oldScript.src;
        } else {
            newScript.textContent = oldScript.textContent;
        }
        try {
            oldScript.parentNode.replaceChild(newScript, oldScript);
        } catch(e) {
            console.error('[ocExecuteScripts] error:', e);
        }
    });
}

// 内部刷新导航（带并发锁）
var __ocNavLock = false;
async function navigateTo(url, tabName, useReplace, skipPathCheck) {
    console.debug('[navigateTo] called', url, tabName, useReplace, '__ocNavLock=' + __ocNavLock);
    // 并发锁：防止快速多次点击冲突
    if (__ocNavLock) {
        console.debug('[navigateTo] blocked by lock');
        return false;
    }
    __ocNavLock = true;
    
    // 安全锁：5 秒后自动释放（防止锁卡死）
    var lockTimer = setTimeout(function() {
        if (__ocNavLock) {
            console.debug('[navigateTo] lock auto-released after timeout');
            __ocNavLock = false;
        }
    }, 5000);
    
    // 避免重复导航到同一页面（含 hash 处理）
    var currentPath = window.location.pathname + window.location.search;
    var targetUrl = url.split('#')[0];
    var targetPath = targetUrl.replace(/^.*?\/\/[^\/]+/, '');
    console.debug('[navigateTo] currentPath=' + currentPath + ' targetPath=' + targetPath);
    if (!skipPathCheck && currentPath === targetPath) {
        console.debug('[navigateTo] same path, skipping');
        clearTimeout(lockTimer);
        __ocNavLock = false;
        return false;
    }
    
    try {
        var cacheBuster = '_t=' + Date.now();
        var fetchUrl = url;
        if (fetchUrl.indexOf('?') >= 0) {
            fetchUrl += '&' + cacheBuster;
        } else {
            fetchUrl += '?' + cacheBuster;
        }
        var response = await fetch(fetchUrl);
        var html = await response.text();
        
        // 解析 HTML
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, 'text/html');
        
        // 提取新内容
        var newContentEl = doc.getElementById('page-content');
        if (!newContentEl) {
            // 降级：直接跳转
            clearTimeout(lockTimer);
            __ocNavLock = false;
            window.location.href = url;
            return false;
        }
        
        // 清理旧页面的定时器
        ocClearPageIntervals();
        
        // 切换页面特定样式：移除旧 style 块，添加新 style 块
        var oldPageStyles = document.querySelectorAll('style[data-page-style]');
        oldPageStyles.forEach(function(s) { s.remove(); });
        
        var newStyles = doc.querySelectorAll('style[data-page-style]');
        newStyles.forEach(function(s) {
            var newStyle = document.createElement('style');
            newStyle.setAttribute('data-page-style', '');
            newStyle.textContent = s.textContent;
            document.head.appendChild(newStyle);
        });
        
        // 清除旧内联主题色（来自 <script> setProperty，不会被 SPA 重新执行）
        document.documentElement.style.removeProperty('--accent-color');
        document.documentElement.style.removeProperty('--accent-gradient-from');
        document.documentElement.style.removeProperty('--accent-gradient-to');

        // 更新页面标题
        document.title = doc.title;
        
        // 更新 URL
        if (useReplace) {
            window.history.replaceState({ tab: tabName, url: url }, '', url);
        } else {
            window.history.pushState({ tab: tabName, url: url }, '', url);
        }
        
        // ===== 顶部标题栏由 TypeScript 管理，不同步 =====
        var pageContentEl = document.getElementById('page-content');
        
        // ===== 处理顶部导航栏（nav-bar）同步 =====
        var currentNavBar = document.querySelector('.nav-bar');
        var newNavBar = doc.querySelector('.nav-bar');
        pageContentEl = document.getElementById('page-content');
        if (newNavBar) {
            if (currentNavBar) {
                currentNavBar.innerHTML = newNavBar.innerHTML;
                currentNavBar.style.display = '';
            } else if (pageContentEl) {
                // 当前 DOM 没有 nav-bar，从响应中创建并插入到 page-content 前面
                var tempDiv = document.createElement('div');
                tempDiv.innerHTML = newNavBar.outerHTML;
                var createdNav = tempDiv.firstElementChild;
                if (createdNav) {
                    pageContentEl.parentNode.insertBefore(createdNav, pageContentEl);
                    ocExecuteScripts(createdNav);
                }
            }
        } else if (currentNavBar) {
            currentNavBar.style.display = 'none';
        }
        
        // ===== 处理底部导航栏显示/隐藏 =====
        var currentBottomNav = document.querySelector('.bottom-nav');
        var newBottomNav = doc.querySelector('.bottom-nav');
        if (newBottomNav) {
            if (currentBottomNav) {
                currentBottomNav.style.display = '';
            } else {
                // 导航到有底部栏的页面，但当前 DOM 中不存在 → 从响应中克隆
                var tempDiv = document.createElement('div');
                tempDiv.innerHTML = newBottomNav.outerHTML;
                var createdNav = tempDiv.firstElementChild;
                if (createdNav) {
                    document.body.appendChild(createdNav);
                }
            }
        } else if (currentBottomNav) {
            currentBottomNav.style.display = 'none';
        }
        
        // 用新页面的 INITIAL_STATE 替换当前 INITIAL_STATE
        var newStateScript = doc.getElementById('__INITIAL_STATE__');
        var stateScript = document.getElementById('__INITIAL_STATE__');
        if (newStateScript && stateScript) {
            stateScript.textContent = newStateScript.textContent;
        }
        
        // 处理 body 类（如 profile-page）
        var body = document.body;
        if (doc.body.className) {
            body.className = doc.body.className;
        } else {
            body.removeAttribute('class');
        }
        
        // 替换内容
        var currentContent = document.getElementById('page-content');
        if (currentContent) {
            currentContent.innerHTML = newContentEl.innerHTML;
            // 重新执行脚本
            ocExecuteScripts(currentContent);
            // TS 重新渲染顶栏和底栏
            if (window.renderLayout) setTimeout(window.renderLayout, 10);
            // 滑入动画
            currentContent.classList.remove('slide-in');
            void currentContent.offsetWidth; // 强制回流重置动画
            currentContent.classList.add('slide-in');
            setTimeout(function() { currentContent.classList.remove('slide-in'); }, 300);
        }
        window.scrollTo(0, 0);
        // 处理 hash 锚点
        var urlHash = url.indexOf('#') >= 0 ? url.substring(url.indexOf('#') + 1) : '';
        if (urlHash) {
            setTimeout(function() {
                var anchor = document.getElementById(urlHash);
                if (anchor) anchor.scrollIntoView({ behavior: 'smooth' });
            }, 150);
        }
        
        // 恢复自定义 accent 颜色（如果之前设置了主题色）
        var savedAccent = localStorage.getItem('forum_accent_color');
        if (savedAccent) {
            document.documentElement.style.setProperty('--accent-color', savedAccent);
            document.documentElement.style.setProperty('--accent-gradient-from', savedAccent);
            document.documentElement.style.setProperty('--accent-gradient-to', savedAccent);
        }
        
        // 所有操作完成，释放并发锁
        clearTimeout(lockTimer);
        __ocNavLock = false;
        
    } catch (e) {
        clearTimeout(lockTimer);
        __ocNavLock = false;
        console.error('内部导航失败，降级为直接跳转', e);
        window.location.href = url;
    }
    return false;
}

// 处理浏览器前进/后退
window.addEventListener('popstate', function(e) {
    if (e.state && e.state.url && e.state.tab) {
        // 浏览器已回退 URL，跳过路径重复检查
        navigateTo(e.state.url, e.state.tab, true, true);
    } else if (!e.state) {
        // 回退到初始无 state 页面 → 降级为直接加载
        window.location.reload();
    }
});

// 事件委托：支持 data-nav-url + data-tab 属性（更可靠，不依赖 inline onclick）
document.addEventListener('click', function(e) {
    var el = e.target.closest('[data-nav-url]');
    if (el) {
        var url = el.getAttribute('data-nav-url');
        var tab = el.getAttribute('data-tab') || 'home';
        e.preventDefault();
        console.debug('[delegated] navigating to', url, tab);
        var result = navigateTo(url, tab);
        // 如果 navigateTo 立即返回 false（被锁），尝试再次
        if (result === false) {
            console.debug('[delegated] navigateTo returned false, retrying in 300ms');
            setTimeout(function() {
                navigateTo(url, tab);
            }, 300);
        }
    }
});
</script>
