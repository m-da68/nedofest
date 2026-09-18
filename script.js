const buyBtn = document.getElementById('buyBtn');
const modal = document.getElementById('modal');
const overlay = document.getElementById('modalOverlay');
const closeBtn = document.getElementById('modalClose');
const form = document.getElementById('ticketForm');
const success = document.getElementById('success');
const closeSuccess = document.getElementById('closeSuccess');
const qty = document.getElementById('qty');
const totalPrice = document.getElementById('totalPrice');

const prices = {
  "1 билет — 2 500 ₽": "2 500 ₽",
  "2 билета — 5 000 ₽": "5 000 ₽",
  "3 билета — 7 200 ₽ (-4%)": "7 200 ₽",
  "4 билета — 9 000 ₽ (-10%)": "9 000 ₽"
};

function openModal(){
  modal.classList.add('open');
  document.body.style.overflow='hidden';
  form.style.display='flex';
  success.classList.remove('open');
}
function closeModal(){
  modal.classList.remove('open');
  document.body.style.overflow='';
}

buyBtn.addEventListener('click', openModal);
overlay.addEventListener('click', closeModal);
closeBtn.addEventListener('click', closeModal);
closeSuccess.addEventListener('click', closeModal);

document.addEventListener('keydown', e=>{
  if(e.key==='Escape' && modal.classList.contains('open')) closeModal();
});

qty.addEventListener('change', ()=>{
  const val = qty.value;
  totalPrice.textContent = prices[val] || "2 500 ₽";
});

form.addEventListener('submit', (e)=>{
  e.preventDefault();
  const btn = form.querySelector('.submit-btn');
  const originalText = btn.textContent;
  btn.textContent = 'ПЕЧАТАЕМ БИЛЕТ...';
  btn.disabled = true;
  
  setTimeout(()=>{
    form.style.display='none';
    success.classList.add('open');
    btn.textContent = originalText;
    btn.disabled = false;
    
    // конфетти эффект в стиле штампа
    const stamp = document.querySelector('.success-stamp');
    stamp.animate([
      {transform:'rotate(-6deg) scale(0.8)', opacity:0},
      {transform:'rotate(-6deg) scale(1.15)', opacity:1},
      {transform:'rotate(-6deg) scale(1)', opacity:1}
    ], {duration:400, easing:'cubic-bezier(.34,1.56,.64,1)'});
    
  }, 900);
});
