// ============ 常用链接卡片组件（可复用） ============
// 使用方式：
// 1. PHP 页面输出 <script id="__LINKS_DATA__" type="application/json">...</script>
// 2. 在需要渲染的位置放一个容器 <div id="links-container"></div>
// 3. 调用 renderLinksCards('#links-container', '常用链接')

interface LinkItem {
  title: string;
  url: string;
  target?: string;
  iconSvg?: string;
}

const DEFAULT_LINK_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';

function _lcEscapeHtml(s: string): string {
  const d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}

function _lcEscapeAttr(s: string): string {
  return s.replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function getLinksData(): LinkItem[] | null {
  const el = document.getElementById('__LINKS_DATA__');
  if (!el) return null;
  try { return JSON.parse(el.textContent || '[]'); } catch { return null; }
}

function buildLinksGridHtml(links: LinkItem[]): string {
  let html = '<div class="links-grid">';
  for (const link of links) {
    const icon = link.iconSvg || DEFAULT_LINK_ICON;
    html += '<a href="' + _lcEscapeAttr(link.url) + '" class="link-card" target="' + (link.target || '_self') + '" data-ripple>' +
      '<span class="link-icon">' + icon + '</span>' +
      '<span class="link-label">' + _lcEscapeHtml(link.title) + '</span>' +
    '</a>';
  }
  html += '</div>';
  return html;
}

function renderLinksCards(containerSelector: string, sectionTitle?: string): void {
  const links = getLinksData();
  if (!links || links.length === 0) return;

  const container = document.querySelector<HTMLElement>(containerSelector);
  if (!container) return;

  let html = '';
  if (sectionTitle) {
    html += '<div class="links-section"><div class="section-title"><span>' + _lcEscapeHtml(sectionTitle) + '</span></div>';
  } else {
    html += '<div class="links-section">';
  }
  html += buildLinksGridHtml(links) + '</div>';
  container.innerHTML = html;

  // 初始化波纹动效
  if (typeof initRippleEffects === 'function') {
    initRippleEffects();
  }
}
