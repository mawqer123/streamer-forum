// ============ 水波纹点击动效（可复用） ============
// 给含 data-ripple 属性的元素添加点击水波纹
// 也可手动调用: applyRippleEffect(element)

function applyRippleEffect(el: HTMLElement): void {
  el.addEventListener('click', function (e: MouseEvent) {
    const rect = el.getBoundingClientRect();
    const size = Math.max(rect.width, rect.height);
    const x = e.clientX - rect.left - size / 2;
    const y = e.clientY - rect.top - size / 2;

    const ripple = document.createElement('span');
    ripple.style.cssText = [
      'position:absolute',
      'top:' + y + 'px',
      'left:' + x + 'px',
      'width:' + size + 'px',
      'height:' + size + 'px',
      'border-radius:50%',
      'background:rgba(255,255,255,0.3)',
      'transform:scale(0)',
      'animation:ripple-effect 0.5s ease-out',
      'pointer-events:none',
    ].join(';');

    el.style.position = 'relative';
    el.style.overflow = 'hidden';
    el.appendChild(ripple);

    setTimeout(function () { ripple.remove(); }, 500);
  });
}

// 向已有的 data-ripple 元素注入动效
function initRippleEffects(): void {
  const els = document.querySelectorAll('[data-ripple]');
  for (let i = 0; i < els.length; i++) {
    const el = els[i] as HTMLElement;
    if (!el.dataset.rippleInited) {
      el.dataset.rippleInited = '1';
      applyRippleEffect(el);
    }
  }
}

// 注入 CSS 动画
function injectRippleStyle(): void {
  if (document.getElementById('__ripple_style__')) return;
  const style = document.createElement('style');
  style.id = '__ripple_style__';
  style.textContent = '@keyframes ripple-effect { to { transform: scale(4); opacity: 0; } }';
  document.head.appendChild(style);
}
