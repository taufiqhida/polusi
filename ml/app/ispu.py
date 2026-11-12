"""Rumus resmi ISPU (Permen LHK No. 14/2020): interpolasi linear tiap polutan, ambil yang terbesar."""
import numpy as np

LEVELS = [0, 50, 100, 200, 300, 500]
BREAKPOINTS = {
    "pm25": [15.5, 55.4, 150.4, 250.4, 500],
    "pm10": [50, 150, 350, 420, 500],
    "so2": [52, 180, 400, 800, 1200],
    "co": [4000, 8000, 15000, 30000, 45000],
    "o3": [120, 235, 400, 800, 1000],
    "no2": [80, 200, 1130, 2260, 3000],
}


def sub_index(pollutant: str, x: np.ndarray) -> np.ndarray:
    """Sub-indeks satu polutan; di atas batas tertinggi = 500."""
    xp = [0.0] + BREAKPOINTS[pollutant]
    return np.where(x > xp[-1], 500.0, np.interp(x, xp, LEVELS))


def from_pollutants(columns: dict[str, np.ndarray]) -> tuple[np.ndarray, np.ndarray]:
    """columns: nama polutan => nilai. Mengembalikan (ispu, nama polutan dominan)."""
    names = [p for p in BREAKPOINTS if p in columns]
    subs = np.vstack([sub_index(p, np.asarray(columns[p], dtype=float)) for p in names])
    return subs.max(axis=0), np.array(names, dtype=object)[subs.argmax(axis=0)]


def category(ispu: np.ndarray) -> np.ndarray:
    return np.select(
        [ispu <= 50, ispu <= 100, ispu <= 200, ispu <= 300],
        ["Baik", "Sedang", "Tidak Sehat", "Sangat Tidak Sehat"],
        "Berbahaya",
    ).astype(object)


def round_half_up(x: np.ndarray) -> np.ndarray:
    """Pembulatan seperti PHP round() (0,5 ke atas), bukan banker's rounding milik numpy."""
    return np.floor(np.asarray(x, dtype=float) + 0.5)
