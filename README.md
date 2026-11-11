# ISPU Indonesia 2026 — Dashboard

Laravel 13 + MySQL 8.4 + Nginx, semuanya di Docker. Tidak butuh GPU.

## Jalankan lokal
```bash
docker compose up -d --build
docker compose exec app sh -c "chmod -R 777 storage bootstrap/cache && php artisan migrate --force && php artisan ispu:import"
```
Buka http://localhost:8095 (port diatur di `.env` root: `APP_PORT`).

## Deploy ke VPS (Ubuntu)
Pakai paket `ispu-deploy.tar.gz` (berisi kode + CSV, tanpa vendor dan tanpa .env):
```bash
scp ispu-deploy.tar.gz root@IP-VPS:/root/
ssh root@IP-VPS
mkdir -p /opt/ispu && tar -xzf /root/ispu-deploy.tar.gz -C /opt/ispu && cd /opt/ispu
sudo bash deploy.sh            # https://polusi.skaladigital.my.id ; atau: sudo PORT=8095 bash deploy.sh
```
`deploy.sh` otomatis memasang Docker (jika belum ada), membuat password database acak,
mengatur mode production (`APP_DEBUG=false`), lalu build, migrasi, import CSV, dan evaluasi model.
Aman dijalankan ulang (misalnya setelah upload versi baru).

## Model prediksi
Halaman `/prediksi` memakai k-Nearest Neighbors murni PHP (CPU). Jalankan
`docker compose exec app php artisan ispu:evaluate` untuk menghitung akurasi yang ditampilkan di halaman.

## Evaluasi model
Halaman `/evaluasi` berisi 5-fold cross-validation untuk baseline, Gaussian Naive Bayes, Decision Tree (CART),
kNN, dan rumus resmi ISPU, dalam 3 skenario fitur (semua fitur, PM2.5 + cuaca, cuaca saja). Isinya:
akurasi dan macro-F1 (± simpangan baku), MAE/RMSE/R², grafik pemilihan k, confusion matrix,
precision/recall/F1 per kelas, dan permutation importance. Hitung ulang (beberapa menit, CPU saja):
`docker compose exec app php artisan ispu:evaluate-models`. Nilai k terbaik otomatis dipakai halaman Prediksi.

## Update data
Ganti CSV di `data/`, lalu jalankan:
`docker compose exec app sh -c "php artisan ispu:import --fresh && php artisan cache:clear && php artisan ispu:evaluate"`.
