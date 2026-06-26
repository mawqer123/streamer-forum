"use strict";
// ============ 常用链接卡片组件（可复用） ============
// 使用方式：
// 1. PHP 页面输出 <script id="__LINKS_DATA__" type="application/json">...</script>
// 2. 在需要渲染的位置放一个容器 <div id="links-container"></div>
// 3. 调用 renderLinksCards('#links-container', '常用链接')
// icon removed - too ugly per user request
function _lcEscapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
function _lcEscapeAttr(s) {
    return s.replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function getLinksData() {
    const el = document.getElementById('__LINKS_DATA__');
    if (!el)
        return null;
    try {
        return JSON.parse(el.textContent || '[]');
    }
    catch (_a) {
        return null;
    }
}
function buildLinksGridHtml(links) {
    let html = '<div class="links-grid">';
    for (const link of links) {
        html += '<a href="' + _lcEscapeAttr(link.url) + '" class="link-card" target="' + (link.target || '_self') + '" data-ripple>' +
            '<span class="link-label">' + _lcEscapeHtml(link.title) + '</span>' +
            '</a>';
    }
    html += '</div>';
    return html;
}
function renderLinksCards(containerSelector, sectionTitle) {
    const links = getLinksData();
    if (!links || links.length === 0)
        return;
    const container = document.querySelector(containerSelector);
    if (!container)
        return;
    let html = '';
    if (sectionTitle) {
        html += '<div class="links-section"><div class="section-title"><span>' + _lcEscapeHtml(sectionTitle) + '</span></div>';
    }
    else {
        html += '<div class="links-section">';
    }
    html += buildLinksGridHtml(links) + '</div>';
    container.innerHTML = html;
    // 初始化波纹动效
    if (typeof initRippleEffects === 'function') {
        initRippleEffects();
    }
}
