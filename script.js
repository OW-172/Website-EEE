const navToggle=document.querySelector('.nav-toggle');const nav=document.querySelector('.nav');navToggle?.addEventListener('click',()=>{const open=nav.classList.toggle('open');navToggle.setAttribute('aria-expanded',open)});document.querySelectorAll('.nav a').forEach(a=>a.addEventListener('click',()=>nav.classList.remove('open')));
const revealObserver=new IntersectionObserver(entries=>entries.forEach(e=>{if(e.isIntersecting)e.target.classList.add('visible')}),{threshold:.12});document.querySelectorAll('.reveal').forEach(el=>revealObserver.observe(el));
const datasets={
 '1fam':['assets/1fam-real.jpg','assets/1fam-ecad.png','Einfamilienhaus'],
 '2fam':['assets/2fam-real.jpg','assets/2fam-ecad.png','Zweifamilienhaus'],
 'mfh':['assets/mfh-real.jpg','assets/mfh-ecad.png','Mehrfamilienhaus'],
 'gewerbe':['assets/gewerbe-real.jpg','assets/gewerbe-ecad.png','Gewerbegebäude'],
 'oeffentlich':['assets/oeffentlich-real.png','assets/oeffentlich-ecad.png','Öffentliches Gebäude']
};
const realImage=document.getElementById('realImage'),cadImage=document.getElementById('cadImage'),overlay=document.getElementById('compareOverlay'),line=document.getElementById('compareLine'),range=document.getElementById('compareRange'),compareFrame=document.getElementById('compareFrame');
function setCompare(v){overlay.style.clipPath=`inset(0 0 0 ${v}%)`;line.style.left=`${v}%`}
range?.addEventListener('input',e=>setCompare(e.target.value));compareFrame?.setAttribute('data-key','1fam');setCompare(50);
function choose(key){const d=datasets[key];if(!d)return;compareFrame?.setAttribute('data-key',key);realImage.src=d[0];cadImage.src=d[1];realImage.alt=`Reales ${d[2]}`;cadImage.alt=`3D-Gebäudemodell ${d[2]}`;range.value=50;setCompare(50);document.querySelectorAll('.ecad-tab').forEach(b=>b.classList.toggle('active',b.dataset.key===key));document.getElementById('ecad')?.scrollIntoView({behavior:'smooth',block:'start'})}
document.querySelectorAll('.ecad-tab').forEach(b=>b.addEventListener('click',()=>choose(b.dataset.key)));document.querySelectorAll('.project-open').forEach(b=>b.addEventListener('click',()=>choose(b.dataset.key)));
