/**
 * Ruang Kuesioner → Google Drive
 *
 * Menyimpan berkas/foto/tanda tangan setiap respons ke folder Drive (subfolder per kuesioner
 * dan per tanggal) dan menambahkan satu baris per respons ke spreadsheet "Rekap - <judul>".
 * Jangan ubah FOLDER_ID dan KUNCI; keduanya dibuat oleh Ruang Kuesioner untuk folder Anda.
 */
const FOLDER_ID = '__FOLDER_ID__';
const KUNCI = '__KEY__';

function doGet() {
  return ContentService.createTextOutput('Ruang Kuesioner → Google Drive aktif.');
}

function doPost(e) {
  const lock = LockService.getScriptLock();
  try {
    const data = JSON.parse(e.postData.contents);
    if (data.key !== KUNCI) return balas({ ok: false, error: 'Kunci tidak cocok. Salin ulang skrip dari Ruang Kuesioner.' });

    const root = DriveApp.getFolderById(FOLDER_ID);
    if (data.ping) return balas({ ok: true, folder: root.getName(), folder_url: root.getUrl() });

    lock.waitLock(30000);
    const folderKuesioner = subfolder(root, data.survey.folder);
    const folderTanggal = subfolder(folderKuesioner, data.response.date);

    // Berkas: kirim ulang respons yang sama menimpa berkas bernama sama, bukan menggandakannya.
    const files = {};
    (data.files || []).forEach(function (f) {
      const lama = folderTanggal.getFilesByName(f.name);
      while (lama.hasNext()) lama.next().setTrashed(true);
      const blob = Utilities.newBlob(Utilities.base64Decode(f.data), f.mime, f.name);
      files[f.key] = folderTanggal.createFile(blob).getUrl();
    });

    const sheet = rekap(folderKuesioner, data.survey.sheet);
    const nilai = {
      'ID respons': data.response.id,
      'Waktu kirim': data.response.submitted_at,
      'Detail di aplikasi': data.response.detail_url,
    };
    data.row.forEach(function (sel) {
      const tautan = (sel[2] || []).map(function (key) { return files[key]; }).filter(String);
      nilai[sel[0]] = tautan.length ? tautan.join('\n') : sel[1];
    });
    tulisBaris(sheet, nilai);

    return balas({ ok: true, folder_url: folderTanggal.getUrl(), sheet_url: sheet.getParent().getUrl(), files: files });
  } catch (err) {
    return balas({ ok: false, error: String(err && err.message ? err.message : err) });
  } finally {
    lock.releaseLock();
  }
}

function subfolder(induk, nama) {
  const ada = induk.getFoldersByName(nama);
  return ada.hasNext() ? ada.next() : induk.createFolder(nama);
}

function rekap(folder, nama) {
  const ada = folder.getFilesByName(nama);
  while (ada.hasNext()) {
    const file = ada.next();
    if (file.getMimeType() === MimeType.GOOGLE_SHEETS) return SpreadsheetApp.openById(file.getId()).getSheets()[0];
  }
  const baru = SpreadsheetApp.create(nama);
  DriveApp.getFileById(baru.getId()).moveTo(folder);
  return baru.getSheets()[0];
}

// Kolom dicocokkan menurut judulnya; pertanyaan baru menambah kolom, respons yang dikirim ulang memperbarui barisnya.
function tulisBaris(sheet, nilai) {
  let judul = sheet.getLastColumn() ? sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0] : [];
  Object.keys(nilai).forEach(function (kolom) {
    if (judul.indexOf(kolom) === -1) {
      judul.push(kolom);
      sheet.getRange(1, judul.length).setValue(kolom).setFontWeight('bold');
    }
  });
  sheet.setFrozenRows(1);

  const baris = judul.map(function (kolom) { return kolom in nilai ? nilai[kolom] : ''; });
  const ids = sheet.getLastRow() > 1 ? sheet.getRange(2, 1, sheet.getLastRow() - 1, 1).getValues().map(function (r) { return String(r[0]); }) : [];
  const posisi = ids.indexOf(String(nilai['ID respons']));
  const nomor = posisi === -1 ? sheet.getLastRow() + 1 : posisi + 2;
  sheet.getRange(nomor, 1, 1, baris.length).setValues([baris]);
}

function balas(isi) {
  return ContentService.createTextOutput(JSON.stringify(isi)).setMimeType(ContentService.MimeType.JSON);
}
