"""Akses data observasi dari MySQL (database yang sama dengan Laravel)."""
import os

import numpy as np
import pymysql

# kolom => (label, satuan); urutan ini dipakai sebagai urutan fitur model
FEATURES = {
    "pm25": ("PM2.5", "µg/m³"),
    "pm10": ("PM10", "µg/m³"),
    "so2": ("SO2", "µg/m³"),
    "no2": ("NO2", "µg/m³"),
    "co": ("CO", "µg/m³"),
    "o3": ("O3", "µg/m³"),
    "temperature": ("Suhu", "°C"),
    "humidity": ("Kelembapan", "%"),
    "wind_speed": ("Kecepatan angin", "km/jam"),
    "rainfall": ("Curah hujan", "mm/jam"),
}
WEATHER = ["temperature", "humidity", "wind_speed", "rainfall"]
CATEGORIES = ["Baik", "Sedang", "Tidak Sehat", "Sangat Tidak Sehat", "Berbahaya"]
TARGETS = ["ispu", "ispu_category", "risk_tier", "ispa_burden"]


class Dataset:
    """Fitur numerik (X, kolom sesuai FEATURES) dan keempat target."""

    def __init__(self, rows: list[dict]):
        self.n = len(rows)
        self.X = np.array([[float(r[f]) for f in FEATURES] for r in rows], dtype=float)
        self.ispu = np.array([float(r["ispu"]) for r in rows])
        self.category = np.array([r["ispu_category"] for r in rows], dtype=object)
        self.tier = np.array([r["risk_tier"] for r in rows], dtype=object)
        self.burden = np.array([float(r["ispa_burden"]) for r in rows])

    def subset(self, idx: np.ndarray) -> "Dataset":
        d = object.__new__(Dataset)
        d.n = len(idx)
        for name in ("X", "ispu", "category", "tier", "burden"):
            setattr(d, name, getattr(self, name)[idx])
        return d

    def target(self, name: str) -> np.ndarray:
        return getattr(self, name)


def connect():
    return pymysql.connect(
        host=os.getenv("DB_HOST", "db"),
        port=int(os.getenv("DB_PORT", "3306")),
        user=os.getenv("DB_USERNAME", "ispu"),
        password=os.getenv("DB_PASSWORD", "ispu_secret"),
        database=os.getenv("DB_DATABASE", "ispu"),
        cursorclass=pymysql.cursors.DictCursor,
        connect_timeout=10,
    )


def load() -> Dataset:
    cols = ", ".join(list(FEATURES) + TARGETS)
    with connect() as conn, conn.cursor() as cur:
        cur.execute(f"SELECT {cols} FROM observations ORDER BY id")
        return Dataset(cur.fetchall())
