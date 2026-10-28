
(function(){
  const page=(location.pathname.split('/').pop()||'index.html').toLowerCase();
  const roleForPage=page.startsWith('class-')?'class_teacher':page.startsWith('subject-')?'subject_teacher':'admin';
  if(page==='login.html'||page==='index.html') return;

  // In the prototype, navigation must never destroy the role session.
  if(!sessionStorage.getItem('prototypeRole')) sessionStorage.setItem('prototypeRole', roleForPage);

  const topbar=document.querySelector('.topbar');
  if(topbar){
    const labels={admin:'Admin',class_teacher:'Class Teacher',subject_teacher:'Subject Teacher'};
    const role=roleForPage;
    const right=topbar.lastElementChild;
    if(right){
      right.className='topbar-user';
      right.innerHTML=`<span class="user-pill">${labels[role]} <span aria-hidden="true">⌄</span></span><a class="logout-link" href="login.html" data-logout>Logout</a>`;
    }
  }
  document.addEventListener('click',e=>{
    const logout=e.target.closest('[data-logout]'); if(logout){e.preventDefault();sessionStorage.removeItem('prototypeRole');location.href='login.html';}
  });

  const icons={
    'Dashboard':'<path d="M3 3h7v7H3zM14 3h7v5h-7zM14 12h7v9h-7zM3 14h7v7H3z"/>',
    'Students':'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    'Import Students':'<path d="M12 3v12M7 10l5 5 5-5"/><path d="M5 21h14"/>',
    'Teachers & RBAC':'<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6M17 11h6"/>',
    'Classes & Sections':'<path d="M4 19.5V5a2 2 0 0 1 2-2h12v16H6a2 2 0 0 0-2 2.5"/><path d="M8 7h6M8 11h6"/>',
    'Subjects':'<path d="M4 19.5V5a2 2 0 0 1 2-2h12v16H6a2 2 0 0 0-2 2.5"/><path d="M9 7h6"/>',
    'Exam Setup':'<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    'Marks Entry':'<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
    'Attendance':'<path d="M8 2v4M16 2v4M3 10h18"/><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M8 15l2 2 5-5"/>',
    'Co-Scholastic Setup':'<path d="M12 2l3 6 6 .9-4.5 4.4 1 6.2-5.5-2.9-5.5 2.9 1-6.2L3 8.9 9 8z"/>',
    'Co-Scholastic':'<path d="M12 2l3 6 6 .9-4.5 4.4 1 6.2-5.5-2.9-5.5 2.9 1-6.2L3 8.9 9 8z"/>',
    'Remarks':'<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/>',
    'Report Card Templates':'<path d="M4 3h16v18H4zM8 7h8M8 11h8M8 15h5"/>',
    'Report Cards':'<path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6M9 13h8M9 17h8"/>',
    'Class Tabulation':'<path d="M3 3h18v18H3zM3 9h18M9 3v18"/>',
    'Tabulation Sheet':'<path d="M3 3h18v18H3zM3 9h18M9 3v18"/>',
    'Result Review':'<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    'Reports':'<path d="M3 3v18h18"/><path d="M7 16v-5M12 16V7M17 16v-9"/>',
    'My Class Students':'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>',
    'Class Marks':'<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
    'Import Marks':'<path d="M12 3v12M7 10l5 5 5-5"/><path d="M5 21h14"/>',
    'My Marks Entry':'<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
    'Performance':'<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 6-7"/>'
  };
  document.querySelectorAll('.nav a').forEach(a=>{
    const label=a.textContent.trim();
    const path=icons[label]||'<circle cx="12" cy="12" r="9"/>';
    a.innerHTML=`<span class="nav-icon"><svg viewBox="0 0 24 24">${path}</svg></span><span>${label}</span>`;
  });
})();
