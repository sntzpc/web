// GANTI dengan URL Web App GAS Anda
const GAS_URL = 'https://script.google.com/macros/s/AKfycbxDcCRwjT-0Ujzy_0Dz3tKEyhybQtZ-1OENkigcJXeSvrjj6Bmn0BzJePr4WKoClsaahA/exec';

const $ = (s)=> document.querySelector(s);
const rupiah = (n)=> Number(n||0).toLocaleString('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0});
const safe   = (v)=> (v==null?'':String(v));

function post(route, payload={}){
  return fetch(GAS_URL, {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({route, ...payload})
  }).then(r=>r.json());
}

async function syncActive(){
  $('#syncMsg').textContent = 'Memproses…';
  const res = await post('pull.active');
  if(!res.ok){ $('#syncMsg').textContent = 'Gagal: ' + (res.error||''); return; }
  $('#syncMsg').textContent = `Inserted ${res.inserted} baris. Total amount: ${rupiah(res.total_amount)}. Active now: ${res.active_count}.`;
  listRevenue();
}
async function listRevenue(){
  const res = await post('revenue.list');
  const tbody = $('#tblRev tbody'); tbody.innerHTML = '';
  if(!res.ok){ tbody.innerHTML = `<tr><td colspan="10">Gagal baca: ${res.error||''}</td></tr>`; return; }
  const hdr = res.header||[], rows = res.data||[];
  const i = (k)=> hdr.indexOf(k);
  const i_ts=i('ts'), i_user=i('user'), i_profile=i('profile'), i_price=i('price'),
        i_login=i('login_time'), i_up=i('uptime'), i_addr=i('address'),
        i_mac=i('mac_address'), i_cmt=i('comment');
  let total=0;
  rows.slice(-100).forEach((r,idx)=>{
    total += Number(r[i_price]||0);
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${idx+1}</td>
      <td>${safe(r[i_ts])}</td>
      <td class="mono">${safe(r[i_user])}</td>
      <td>${safe(r[i_profile])}</td>
      <td>${rupiah(r[i_price])}</td>
      <td class="mono">${safe(r[i_login])}</td>
      <td>${safe(r[i_up])}</td>
      <td class="mono">${safe(r[i_addr])}</td>
      <td class="mono">${safe(r[i_mac])}</td>
      <td>${safe(r[i_cmt])}</td>
    `;
    tbody.appendChild(tr);
  });
  $('#sumTotal').textContent = rupiah(total);
}

document.addEventListener('DOMContentLoaded', ()=>{
  $('#btnSync').addEventListener('click', syncActive);
  $('#btnList').addEventListener('click', listRevenue);
  listRevenue();
});
