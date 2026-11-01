(function(){
  const saved = localStorage.getItem('srnu-theme') || 'light';
  document.documentElement.dataset.theme = saved;
})();
document.addEventListener('DOMContentLoaded',()=>{
  const btn=document.querySelector('[data-srnu-menu]');
  const nav=document.querySelector('.srnu-mobile-nav');
  if(btn&&nav) btn.addEventListener('click',()=>nav.classList.toggle('open'));
  document.querySelectorAll('[data-theme-toggle]').forEach(b=>b.addEventListener('click',()=>{
    const next=(document.documentElement.dataset.theme||'light')==='dark'?'light':'dark';
    document.documentElement.dataset.theme=next;localStorage.setItem('srnu-theme',next);
  }));
});
