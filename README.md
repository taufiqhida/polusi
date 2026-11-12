# ISPU Indonesia 2026 — Dashboard

Laravel 13 + MySQL 8.4 + Nginx + service ML Python (FastAPI, scikit-learn), semuanya di Docker. Tidak butuh GPU.

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

## Model prediksi (Python)
Semua ML berjalan di container `ml` (folder `ml/`): **FastAPI + scikit-learn**, membaca data langsung dari MySQL.
Laravel hanya memanggilnya lewat HTTP internal (`http://ml:8000`, tidak dibuka ke luar).
Model kNN terlatih disimpan di volume `mlmodels`, jadi tidak perlu dilatih ulang saat container restart.

| Endpoint | Fungsi |
|---|---|
| `GET /health` | status service dan model aktif |
| `POST /predict` | prediksi dari 10 fitur (dipakai halaman `/prediksi`) |
| `POST /train` | latih ulang kNN dari seluruh data dengan `k` tertentu |
| `POST /evaluate` | uji hold-out (akurasi di halaman Prediksi) |
| `POST /evaluate-models` | 5-fold CV lengkap (halaman Evaluasi), lalu melatih ulang kNN dengan k terbaik |

Perintah artisan tetap sama, sekarang meneruskan pekerjaannya ke service ML:
`docker compose exec app php artisan ispu:evaluate` (uji hold-out + latih ulang model prediksi).
Progres evaluasi bisa dilihat dengan `docker compose logs -f ml`.

## Evaluasi model
Halaman `/evaluasi` berisi 5-fold cross-validation untuk baseline, Gaussian Naive Bayes, Decision Tree (CART),
kNN, dan rumus resmi ISPU, dalam 3 skenario fitur (semua fitur, PM2.5 + cuaca, cuaca saja). Isinya:
akurasi dan macro-F1 (± simpangan baku), MAE/RMSE/R², grafik pemilihan k, confusion matrix,
precision/recall/F1 per kelas, dan permutation importance. Hitung ulang (± 30 detik, CPU saja):
`docker compose exec app php artisan ispu:evaluate-models`. Nilai k terbaik otomatis dipakai halaman Prediksi.

## Update data
Ganti CSV di `data/`, lalu jalankan:
`docker compose exec app sh -c "php artisan ispu:import --fresh && php artisan cache:clear && php artisan ispu:evaluate"`.
