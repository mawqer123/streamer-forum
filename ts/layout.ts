// ============ 全局状态接口 ============
interface InitialState {
  isLoggedIn: boolean;
  isAdmin: boolean;
  userId: number | null;
  username: string | null;
  avatarHtml: string | null;
  unreadCount: number;
  chatUnread: number;
  pmUnread: number;
  auditPending: number;
  currentTab: string;
  hideTopBar: boolean;
  hideBottomBar: boolean;
  siteUrl: string;
  urls: {
    search: string;
    admin: string;
    profile: string;
    level: string;
    notifications: string;
  };
}

// ============ 工具函数 ============
function getState(): InitialState | null {
  const el = document.getElementById('__INITIAL_STATE__');
  if (!el) return null;
  try { return JSON.parse(el.textContent || '{}'); } catch { return null; }
}

function _escapeHtml(s: string): string {
  const d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}

const TITLE = '主播模拟器论坛';

// ============ SVG 图标 ============
const ICONS = {
  home: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
  bell: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
  user: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
  search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>',
  admin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2Zm10-10V7a4 4 0 0 0-8 0v4h8Z"/></svg>',
  star: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
  logout: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
};

// ============ 顶栏渲染 ============
function renderTopBar(state: InitialState): void {
  if (state.hideTopBar) { const el = document.getElementById('top-bar'); if (el) { el.style.display = 'none'; } return; }
  const el = document.getElementById('top-bar');
  if (!el) { document.write('<!-- top-bar not found -->'); return; }
  el.style.display = '';
  
  let html = '<div style="display:flex;align-items:center;justify-content:space-between;padding:0 1rem;height:56px;background:var(--accent-gradient-from);">';
  html += '<h1 style="margin:0;font-size:1.1rem;font-weight:700;color:white;">' + TITLE + '</h1>';
  html += '<div style="display:flex;align-items:center;gap:0.5rem;">';
  html += '<a href="' + state.urls.search + '" class="top-icon-btn" style="color:white;display:flex;padding:0.5rem;" title="搜索">' + ICONS.search + '</a>';
  if (state.isAdmin) {
    html += '<a href="' + state.urls.admin + '" class="top-icon-btn" style="color:white;display:flex;padding:0.5rem;" title="管理">' + ICONS.admin + '</a>';
  }
  if (state.isLoggedIn) {
    html += '<div class="text-avatar" title="' + _escapeHtml(state.username || '') + '" onclick="toggleUserMenu()" style="width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,0.2);color:white;display:flex;align-items:center;justify-content:center;font-weight:600;cursor:pointer;overflow:hidden;border:2px solid rgba(255,255,255,0.5);">' + (state.avatarHtml || state.username?.charAt(0)?.toUpperCase() || '?') + '</div>';
    html += '<div id="userMenu" style="display:none;position:absolute;top:52px;right:1rem;background:white;box-shadow:0 4px 20px rgba(0,0,0,0.15);min-width:180px;z-index:1001;">';
    html += '<a href="' + state.urls.profile + '" style="display:flex;align-items:center;padding:0.75rem 1rem;color:#333;text-decoration:none;border-bottom:1px solid #eee;font-size:0.9rem;"><span style="width:18px;height:18px;margin-right:0.5rem;color:#666;display:inline-flex;">' + ICONS.user + '</span><span>个人中心</span></a>';
    html += '<a href="' + state.urls.level + '" style="display:flex;align-items:center;padding:0.75rem 1rem;color:#333;text-decoration:none;border-bottom:1px solid #eee;font-size:0.9rem;"><span style="width:18px;height:18px;margin-right:0.5rem;color:#666;display:inline-flex;">' + ICONS.star + '</span><span>我的等级</span></a>';
    if (state.isAdmin) {
      html += '<a href="' + state.urls.admin + '" style="display:flex;align-items:center;padding:0.75rem 1rem;color:#333;text-decoration:none;border-bottom:1px solid #eee;font-size:0.9rem;"><span style="width:18px;height:18px;margin-right:0.5rem;color:#666;display:inline-flex;">' + ICONS.admin + '</span><span>后台管理</span></a>';
    }
    html += '<div style="height:1px;background:#eee;margin:4px 0;"></div>';
    html += '<a href="#" onclick="logoutAction();return false;" style="display:flex;align-items:center;padding:0.75rem 1rem;color:#333;text-decoration:none;font-size:0.9rem;"><span style="width:18px;height:18px;margin-right:0.5rem;color:#666;display:inline-flex;">' + ICONS.logout + '</span><span>退出登录</span></a>';
    html += '</div>';
  } else {
    html += '<button onclick="showAuthModal(true)" style="padding:0.4rem 0.9rem;border:2px solid rgba(255,255,255,0.5);background:transparent;color:white;font-size:0.85rem;font-weight:600;cursor:pointer;">登录</button>';
    html += '<button onclick="showAuthModal(false)" style="padding:0.4rem 0.9rem;border:none;background:white;color:#2196F3;font-size:0.85rem;font-weight:600;cursor:pointer;">注册</button>';
  }
  html += '</div></div>';
  el.innerHTML = html;
}

// ============ 底栏渲染 ============
function renderBottomNav(state: InitialState): void {
  if (state.hideBottomBar) {
    const el = document.getElementById('bottom-bar');
    if (el) { el.style.display = 'none'; }
    return;
  }
  const el = document.getElementById('bottom-bar');
  if (!el) return;
  el.style.display = '';
  
  const tabs = [
    { id: 'home', label: '首页', icon: ICONS.home },
    { id: 'notifications', label: '消息', icon: ICONS.bell },
    { id: 'profile', label: '我的', icon: ICONS.user },
  ];
  
  let html = '<div style="display:flex;justify-content:space-around;align-items:center;height:60px;max-width:800px;margin:0 auto;">';
  
  for (const tab of tabs) {
    const isActive = state.currentTab === tab.id;
    const isLoggedIn = state.isLoggedIn;
    const showBadge = tab.id === 'notifications' && isLoggedIn && state.unreadCount > 0;
    
    html += '<div class="bottom-nav-item" data-tab="' + tab.id + '" style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:pointer;padding:0.5rem 0;color:' + (isActive ? 'var(--accent-color)' : 'var(--text-secondary)') + ';">';
    html += '<div style="position:relative;display:flex;align-items:center;justify-content:center;">';
    html += '<span style="width:24px;height:24px;margin-bottom:4px;">' + tab.icon + '</span>';
    if (showBadge) {
      html += '<span style="position:absolute;top:-4px;right:-6px;background:#e53e3e;color:white;font-size:0.7rem;font-weight:bold;min-width:16px;height:16px;display:flex;align-items:center;justify-content:center;padding:0 4px;box-shadow:0 2px 4px rgba(0,0,0,0.2);">' + (state.unreadCount > 9 ? '9+' : state.unreadCount) + '</span>';
    }
    html += '</div>';
    html += '<span style="font-size:0.75rem;line-height:1;">' + tab.label + '</span>';
    html += '</div>';
  }
  
  html += '</div>';
  el.innerHTML = html;
}

// ============ 用户菜单 ============
function toggleUserMenu(): void {
  const m = document.getElementById('userMenu');
  if (m) m.style.display = m.style.display === 'block' ? 'none' : 'block';
}

// 点击外部关闭
document.addEventListener('click', (e: MouseEvent) => {
  const m = document.getElementById('userMenu');
  const a = document.querySelector('.text-avatar');
  if (m && a && m.style.display === 'block' && !m.contains(e.target as Node) && !a.contains(e.target as Node)) {
    m.style.display = 'none';
  }
});

// ============ 退出登录 ============
(window as any).logoutAction = function(): void {
  if (!confirm('确定退出？')) return;
  const fd = new FormData();
  fd.append('action', 'logout');
  fetch('auth.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => { if (d.success) location.href = '/'; else alert(d.message); })
    .catch(() => alert('网络错误'));
};

// ============ 底栏点击 ============
function initBottomNavClicks(): void {
  document.addEventListener('click', (e: MouseEvent) => {
    const item = (e.target as HTMLElement).closest('.bottom-nav-item') as HTMLElement | null;
    if (!item) return;
    const tab = item.getAttribute('data-tab');
    if (!tab) return;
    const state = getState();
    if (!state) return;
    
    if (!state.isLoggedIn && (tab === 'notifications' || tab === 'profile')) {
      (window as any).showAuthModal?.(true);
      return;
    }
    
    const urls: Record<string, string> = {
      home: '/',
      notifications: state.urls.notifications,
      profile: state.urls.profile,
      chat: '/chatroom',
    };
    (window as any).navigateTo?.(urls[tab] || '/', tab);
  });
}

// ============ 启动 ============
function renderLayout(): void {
  const state = getState();
  if (!state) return;
  renderTopBar(state);
  renderBottomNav(state);
}

// 立即执行
renderLayout();

// 底栏点击（只需绑定一次）
initBottomNavClicks();

// DOMContentLoaded 时再次渲染（确保底栏可用）
document.addEventListener('DOMContentLoaded', renderLayout);

// 导出给 SPA 导航调用
(window as any).renderLayout = renderLayout;
