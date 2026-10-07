# Menyiapkan absensi bersama dengan MySQL

Versi ini memakai PHP + MySQL. Jangan masukkan kredensial MySQL ke HTML; koneksi database hanya dilakukan server PHP.

## Persiapan hosting

Hosting harus mendukung PHP 8.1 atau lebih baru, ekstensi `PDO_MYSQL`, database MySQL 8/MariaDB, dan HTTPS. Buka situs lewat alamat hosting, bukan dengan membuka `index.html` sebagai file lokal.

## Setup database dan API

1. Buat database MySQL dan user database melalui panel hosting. Beri user hak akses pada database tersebut.
2. Untuk pemasangan baru, di phpMyAdmin pilih database dari panel hosting, lalu impor `database.sql`. File ini membuat tabel dan menambahkan empat akun peserta. File sengaja tidak membuat atau memilih database karena hosting bersama seperti InfinityFree memakai nama database berawalan prefix dan biasanya melarang `CREATE DATABASE`.
   - Jika aplikasi sudah memakai database lama, jangan impor ulang `database.sql` karena data akun awal dapat diubah. Impor `database-migration-absence.sql` satu kali untuk kolom status/keterangan absensi, dan `database-migration-divisions.sql` satu kali untuk tabel divisi.
3. Isi `DB_HOST`, `DB_NAME`, `DB_USER`, dan `DB_PASSWORD` pada `api/config.php`. Gunakan akun database khusus aplikasi dengan hak minimum pada database aplikasi.
4. Unggah `index.html`, `riwayat.html`, `divisi.html`, dan folder `api/` ke hosting. Saat memperbarui API, unggah `api/index.php` terbaru tetapi jangan menimpa `api/config.php` yang sudah berisi konfigurasi database. File SQL hanya perlu diimpor melalui phpMyAdmin. Jangan mengunggah file `.env` atau folder `node_modules`.
5. SQL menyiapkan empat akun awal beserta hash kata sandinya. Untuk mengganti atau membuat akun, pengelola dapat menjalankan `php api/create-user.php` dari terminal server; ikuti prompt dan gunakan kata sandi sementara yang kuat minimal 12 karakter. Skrip CLI tidak dapat dijalankan melalui browser.
6. Peserta masuk di situs memakai email masing-masing. Menu **Riwayat absensi** membuka halaman khusus dengan filter bulan dan unduh CSV. Menu **Kelola divisi** memungkinkan semua peserta menambah, mengubah, dan menghapus nama divisi bersama.

Absensi masuk/pulang dan keterangan Tidak hadir/Sakit dicatat oleh server dalam zona waktu WIB. Peserta hanya dapat mengirim satu catatan per hari; keterangan tambahan akan terlihat di halaman riwayat bersama dan pada CSV. Database membatasi satu catatan per anggota per hari. Kata sandi disimpan sebagai hash, dan sesi login memakai cookie HttpOnly serta token CSRF.

Jika saat menyimpan keterangan muncul pesan **“Aksi tidak ditemukan”**, `api/index.php` di hosting masih versi lama. Unggah file `api/index.php` terbaru dari folder proyek (jangan ganti `api/config.php`), lalu impor migrasi database yang belum diterapkan satu kali ke database aplikasi yang sudah ada.

Jika layar login menyebut tabel belum ditemukan, pastikan `database.sql` diimpor ke database yang sama dengan `DB_NAME` pada `api/config.php`. Jika pesannya menyebut akses ditolak, cocokkan empat nilai konfigurasi dengan panel hosting. Detail teknis tersedia di PHP error log; rahasia database tidak ditampilkan kepada pengunjung.

Riwayat lama yang tersimpan di localStorage browser tidak otomatis dipindahkan ke database. Jika kredensial MySQL pernah diletakkan pada berkas yang dapat diakses publik, segera ganti kata sandi database melalui panel hosting. Mengimpor ulang SQL akan menyetel ulang kata sandi untuk empat akun awal.
