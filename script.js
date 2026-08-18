const navToggle=document.querySelector('.nav-toggle');const nav=document.querySelector('.nav');navToggle?.addEventListener('click',()=>{const open=nav.classList.toggle('open');navToggle.setAttribute('aria-expanded',open)});document.querySelectorAll('.nav a').forEach(a=>a.addEventListener('click',()=>nav.classList.remove('open')));
const revealObserver=new IntersectionObserver(entries=>entries.forEach(e=>{if(e.isIntersecting)e.target.classList.add('visible')}),{threshold:.12});document.querySelectorAll('.reveal').forEach(el=>revealObserver.observe(el));
const datasets={
 '1fam':['assets/1fam-real.webp','assets/1fam-ecad.webp','Einfamilienhaus'],
 '2fam':['assets/2fam-real.webp','assets/2fam-ecad.webp','Zweifamilienhaus'],
 'mfh':['assets/mfh-real.webp','assets/mfh-ecad.webp','Mehrfamilienhaus'],
 'fertigung':['assets/fertigung-real.webp','assets/fertigung-ecad.webp','Fertigungsgebäude'],
 'buero':['assets/buero-real.webp','assets/buero-ecad.webp','Bürogebäude'],
 'oeffentlich':['assets/oeffentlich-real.webp','assets/oeffentlich-ecad.webp','Öffentliches Gebäude']
};
const realImage=document.getElementById('realImage'),cadImage=document.getElementById('cadImage'),overlay=document.getElementById('compareOverlay'),line=document.getElementById('compareLine'),range=document.getElementById('compareRange'),compareFrame=document.getElementById('compareFrame');
function setCompare(v){overlay.style.clipPath=`inset(0 0 0 ${v}%)`;line.style.left=`${v}%`}
range?.addEventListener('input',e=>setCompare(e.target.value));compareFrame?.setAttribute('data-key','1fam');setCompare(50);
function choose(key){const d=datasets[key];if(!d)return;compareFrame?.setAttribute('data-key',key);realImage.src=d[0];cadImage.src=d[1];realImage.alt=`Reales ${d[2]}`;cadImage.alt=`3D-Gebäudemodell ${d[2]}`;range.value=50;setCompare(50);document.querySelectorAll('.ecad-tab').forEach(b=>b.classList.toggle('active',b.dataset.key===key));document.getElementById('ecad')?.scrollIntoView({behavior:'smooth',block:'start'})}
document.querySelectorAll('.ecad-tab').forEach(b=>b.addEventListener('click',()=>choose(b.dataset.key)));document.querySelectorAll('.project-open').forEach(b=>b.addEventListener('click',()=>choose(b.dataset.key)));

// V17.3 – FAQ: immer nur ein Eintrag gleichzeitig geöffnet
const faqItems=document.querySelectorAll('.faq-item');
faqItems.forEach(item=>{
  item.addEventListener('toggle',()=>{
    if(!item.open)return;
    faqItems.forEach(other=>{
      if(other!==item)other.open=false;
    });
  });
});

// V18.2 – Produktiver Kontaktformular-Versand mit Cloudflare Turnstile
const contactForm=document.getElementById('anfrageformular');
const formMsg=document.getElementById('formMsg');
contactForm?.addEventListener('submit',async event=>{
  event.preventDefault();
  const submitButton=contactForm.querySelector('button[type="submit"]');
  const originalText=submitButton?.textContent||'Erstgespräch anfragen';

  if(formMsg)formMsg.textContent='Nachricht wird gesendet …';
  if(submitButton){
    submitButton.disabled=true;
    submitButton.textContent='Wird gesendet …';
  }

  try{
    const response=await fetch(contactForm.action,{
      method:'POST',
      body:new FormData(contactForm),
      headers:{'Accept':'application/json'}
    });
    const result=await response.json().catch(()=>({success:false,message:'Beim Versand ist ein technischer Fehler aufgetreten.'}));

    if(!response.ok||!result.success){
      throw new Error(result.message||'Die Nachricht konnte nicht gesendet werden.');
    }

    if(formMsg)formMsg.textContent=result.message||'Danke! Ihre Anfrage wurde erfolgreich versendet.';
    contactForm.reset();
    if(window.turnstile)window.turnstile.reset();
  }catch(error){
    if(formMsg)formMsg.textContent=error.message||'Die Nachricht konnte nicht gesendet werden. Bitte versuchen Sie es erneut oder rufen Sie uns an.';
    if(window.turnstile)window.turnstile.reset();
  }finally{
    if(submitButton){
      submitButton.disabled=false;
      submitButton.textContent=originalText;
    }
  }
});
