const buyBtn = document.getElementById('buyBtn');
const modal = document.getElementById('modal');
const overlay = document.getElementById('modalOverlay');
const closeBtn = document.getElementById('modalClose');
const form = document.getElementById('ticketForm');
const success = document.getElementById('success');
const closeSuccess = document.getElementById('closeSuccess');
const qty = document.getElementById('qty');
const totalPrice = document.getElementById('totalPrice');
const content = document.getElementById('content');
const ctaFloat = document.getElementById('ctaFloat');
const themeBtn = document.getElementById('themeToggle');
const themeLabel = document.getElementById('themeLabel');

const prices = {
  "1 билет — 2 500 ₽": "2 500 ₽",
  "2 билета — 5 000 ₽": "5 000 ₽",
  "3 билета — 7 200 ₽ (-4%)": "7 200 ₽",
  "4 билета — 9 000 ₽ (-10%)": "9 000 ₽"
};

/* =========================================================
   ТЕМА: АВТО (как в системе) → СВЕТЛАЯ → ТЁМНАЯ
   Выбор запоминается в localStorage, по умолчанию — «АВТО»
   ========================================================= */
const THEMES = [
  { id: 'auto',  icon: '◐', label: 'АВТО' },
  { id: 'light', icon: '☀', label: 'ДЕНЬ' },
  { id: 'dark',  icon: '☾', label: 'НОЧЬ' }
];
const darkMQ = window.matchMedia('(prefers-color-scheme: dark)');

function storedTheme(){
  try { return localStorage.getItem('nf-theme') || 'auto'; } catch(e){ return 'auto'; }
}
let currentTheme = storedTheme();

function applyTheme(id){
  currentTheme = THEMES.some(t => t.id === id) ? id : 'auto';
  const meta = THEMES.find(t => t.id === currentTheme);
  document.documentElement.setAttribute('data-theme', currentTheme);
  try { localStorage.setItem('nf-theme', currentTheme); } catch(e){}

  const effective = currentTheme === 'dark' || (currentTheme === 'auto' && darkMQ.matches) ? 'тёмная' : 'светлая';
  themeLabel.textContent = meta.label;
  themeBtn.querySelector('.tg-icon').textContent = meta.icon;
  themeBtn.title = 'Тема: ' + meta.label + ' (сейчас ' + effective + '). Нажмите, чтобы переключить';
  themeBtn.setAttribute('aria-label', themeBtn.title);
}

themeBtn.addEventListener('click', () => {
  const i = THEMES.findIndex(t => t.id === currentTheme);
  applyTheme(THEMES[(i + 1) % THEMES.length].id);
});

// если выбрано «АВТО» — мгновенно реагируем на смену системной темы
if (darkMQ.addEventListener){
  darkMQ.addEventListener('change', () => { if (currentTheme === 'auto') applyTheme('auto'); });
} else if (darkMQ.addListener){
  darkMQ.addListener(() => { if (currentTheme === 'auto') applyTheme('auto'); });
}

applyTheme(currentTheme);

/* =========================================================
   МОДАЛКА
   ========================================================= */
function openModal(){
  modal.classList.add('open');
  modal.setAttribute('aria-hidden', 'false');
  document.body.style.overflow = 'hidden';
  form.style.display = 'flex';
  success.classList.remove('open');
  syncCta();
}
function closeModal(){
  modal.classList.remove('open');
  modal.setAttribute('aria-hidden', 'true');
  document.body.style.overflow = '';
  syncCta();
}

buyBtn.addEventListener('click', openModal);
overlay.addEventListener('click', closeModal);
closeBtn.addEventListener('click', closeModal);
closeSuccess.addEventListener('click', closeModal);

document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
});

qty.addEventListener('change', () => {
  totalPrice.textContent = prices[qty.value] || "2 500 ₽";
});

form.addEventListener('submit', () => {
  const btn = form.querySelector('.submit-btn');
  if (!btn) return;

  btn.textContent = 'ПЕРЕХОДИМ К ОПЛАТЕ...';
}, 900);

/* =========================================================
   КНОПКА «КУПИТЬ БИЛЕТ» НЕ ДОЛЖНА ПРОПАДАТЬ
   Основная приклеена к низу описания. Если её всё-таки
   не видно (узкий/низкий экран) — показываем плавающую.
   ========================================================= */
function syncCta(){
  if (!ctaFloat || !buyBtn) return;
  const vh = window.innerHeight || document.documentElement.clientHeight;
  const r = buyBtn.getBoundingClientRect();
  const seen = Math.min(r.bottom, vh) - Math.max(r.top, 0);
  const visible = r.height > 0 && seen >= r.height * 0.6;
  const show = !visible && !modal.classList.contains('open');
  ctaFloat.classList.toggle('is-visible', show);
  ctaFloat.setAttribute('aria-hidden', show ? 'false' : 'true');
  ctaFloat.tabIndex = show ? 0 : -1;
}

ctaFloat.addEventListener('click', openModal);
window.addEventListener('scroll', syncCta, { passive: true });
window.addEventListener('resize', syncCta);
if (content) content.addEventListener('scroll', syncCta, { passive: true });
window.addEventListener('load', syncCta);
window.addEventListener('orientationchange', syncCta);
if (document.fonts && document.fonts.ready) document.fonts.ready.then(syncCta);
setTimeout(syncCta, 300);
syncCta();
