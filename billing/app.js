// app.js — Hotspot Mini-Billing (Frontend)
const GAS_URL = 'https://script.google.com/macros/s/AKfycby8xv2OwBGy8LpOFixi4-BHUeJlwp6ehFVaAlaEuXTPha-tEAqrQRgA8N3ijLi9Hdy9NQ/exec'; // <-- Ganti dgn Web App URL hasil deploy GAS

const $ = (sel)=> document.querySelector(sel);
function rupiah(n){
  const v = Number(n||0);
  return v.toLocaleString('id-ID', { style:'currency', currency:'IDR', maximumFractionDigits:0 });
}
function jsonPOST(route, payload={}){
  return fetch(GAS_URL, {
    method:'POST',
    headers:{ 'Content-Type':'application/json' },
    body: JSON.stringify({ route, ...payload }),
  }).then(r=>r.json());
}

async function saveConf(){
  const host = $('#mtHost').value.trim();
  const user = $('#mtUser').value.trim();
  const pass = $('#mtPass').value;
  if(!host || !user){ alert('Host dan username wajib diisi.'); return; }
  const res = await jsonPOST('config.save', { host, user, pass });
  if(res.ok){
    $('#confNote').textContent = 'Konfigurasi tersimpan di server.';
  } else {
    $('#confNote').textContent = 'Gagal simpan: '+(res.error||'');
  }
}
async function syncActive(){
  $('#syncMsg').textContent = 'Memproses…';
  const res = await jsonPOST('pull.active',{});
  if(!res.ok){ $('#syncMsg').textContent = 'Gagal: ' + (res.error||''); return; }
  const msg = `Inserted ${res.inserted} baris baru. Total amount: ${rupiah(res.total_amount)}. Active now: ${res.active_count}.`;
  $('#syncMsg').textContent = msg;
  await listRevenue(); // refresh tabel
}
async function listRevenue(){
  const res = await jsonPOST('revenue.list',{});
  const tbody = $('#tblRev tbody');
  tbody.innerHTML = '';
  let total = 0;
  if(!res.ok){ tbody.innerHTML = `<tr><td colspan="10">Gagal baca: ${res.error||''}</td></tr>`; return; }
  const hdr = res.header || [];
  const rows = res.data || [];
  // map kolom
  const idx = (name)=> hdr.indexOf(name);
  const i_ts=idx('ts'), i_user=idx('user'), i_profile=idx('profile'), i_price=idx('price'),
        i_login=idx('login_time'), i_up=idx('uptime'), i_addr=idx('address'),
        i_mac=idx('mac_address'), i_cmt=idx('comment');
  rows.slice(-100).forEach((r, i)=>{
    total += Number(r[i_price]||0);
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${i+1}</td>
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
function safe(v){ return (v==null)?'':String(v); }

document.addEventListener('DOMContentLoaded', ()=>{
  $('#btnSave').addEventListener('click', saveConf);
  $('#btnSync').addEventListener('click', syncActive);
  $('#btnListRev').addEventListener('click', listRevenue);
  // muat awal
  listRevenue();
});
