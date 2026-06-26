// theme.js - 全局主题管理（存储同步）
(function() {
    // 监听 localStorage 变化（跨标签页同步）
    window.addEventListener('storage', function(e) {
        if (e.key === 'forum_theme') {
            var newTheme = e.newValue;
            document.documentElement.setAttribute('data-theme', newTheme || 'light');
        }
        if (e.key === 'forum_accent_color') {
            var color = e.newValue;
            if (color) {
                document.documentElement.style.setProperty('--accent-color', color);
                document.documentElement.style.setProperty('--accent-gradient-from', color);
                document.documentElement.style.setProperty('--accent-gradient-to', color);
            }
        }
    });

    // 页面加载时，根据 localStorage 初始化
    var savedTheme = localStorage.getItem('forum_theme') || 'light';
    if (savedTheme === 'custom') {
        // 自定义主题：data-theme 用 forum_theme_mode（light/dark），不设 'custom'
        var mode = localStorage.getItem('forum_theme_mode') || 'light';
        document.documentElement.setAttribute('data-theme', mode);
        // 恢复自定义 accent 颜色
        var accentColor = localStorage.getItem('forum_accent_color');
        if (accentColor) {
            document.documentElement.style.setProperty('--accent-color', accentColor);
            document.documentElement.style.setProperty('--accent-gradient-from', accentColor);
            document.documentElement.style.setProperty('--accent-gradient-to', accentColor);
        }
    } else {
        document.documentElement.setAttribute('data-theme', savedTheme);
    }

    // ===== 全局图片加载失败兜底 =====
    (function() {
        var FALLBACK_IMG = '/404_image_error.svg';
        var FALLBACK_KEY = '__img_fallback_set';

        function handleImgError() {
            if (this[FALLBACK_KEY]) return;
            this[FALLBACK_KEY] = true;
            this.onerror = null;
            this.src = FALLBACK_IMG;
        }

        // 直接给单个图片绑定 onerror（最可靠）
        function patchImg(img) {
            if (img[FALLBACK_KEY]) return;
            // 已经加载完成且失败 → 直接替换
            if (img.complete && img.naturalWidth === 0 && img.naturalHeight === 0) {
                img[FALLBACK_KEY] = true;
                img.onerror = null;
                img.src = FALLBACK_IMG;
                return;
            }
            // 还没加载 → 设置 onerror
            img.onerror = handleImgError;
        }

        // 修补页面上已有的所有图片
        function patchAll() {
            var imgs = document.getElementsByTagName('img');
            for (var i = 0; i < imgs.length; i++) patchImg(imgs[i]);
        }

        // DOM 就绪后修补
        function ready(fn) {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fn);
            } else {
                fn();
            }
        }

        ready(patchAll);

        // MutationObserver 监听后续 DOM 新增的图片
        ready(function() {
            var ob = new MutationObserver(function(mutations) {
                mutations.forEach(function(m) {
                    for (var i = 0; i < m.addedNodes.length; i++) {
                        var node = m.addedNodes[i];
                        if (node.nodeType === 1) {
                            if (node.tagName === 'IMG') patchImg(node);
                            var children = node.getElementsByTagName ? node.getElementsByTagName('img') : null;
                            if (children) {
                                for (var j = 0; j < children.length; j++) patchImg(children[j]);
                            }
                        }
                    }
                });
            });
            ob.observe(document.body, { childList: true, subtree: true });

            // 最后一道防线：捕获阶段兜底（处理不经过 DOM 插入的 img）
            document.addEventListener('error', function(e) {
                var t = e.target;
                if (!t || t.tagName !== 'IMG') return;
                if (t[FALLBACK_KEY]) return;
                t[FALLBACK_KEY] = true;
                t.onerror = null;
                t.src = FALLBACK_IMG;
            }, true);
        });
    })();
})();
