"""Service ML (FastAPI) untuk dashboard ISPU. Hanya bisa diakses dari jaringan Docker internal."""
import logging
import os
import threading
from pathlib import Path

import joblib
import numpy as np
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

from . import data
from .evaluate import evaluate_models, holdout
from .knn import KnnPredictor

log = logging.getLogger("uvicorn.error")
MODEL_FILE = Path(os.getenv("MODEL_DIR", "/models")) / "knn.joblib"
DEFAULT_K = 15

app = FastAPI(title="ISPU ML service")
_lock = threading.Lock()
_model: KnnPredictor | None = None


class Features(BaseModel):
    pm25: float = Field(ge=0)
    pm10: float = Field(ge=0)
    so2: float = Field(ge=0)
    no2: float = Field(ge=0)
    co: float = Field(ge=0)
    o3: float = Field(ge=0)
    temperature: float
    humidity: float = Field(ge=0, le=100)
    wind_speed: float = Field(ge=0)
    rainfall: float = Field(ge=0)


class TrainRequest(BaseModel):
    k: int = Field(DEFAULT_K, ge=1, le=200)


class HoldoutRequest(TrainRequest):
    test: int = Field(1000, ge=10)


class EvaluateRequest(BaseModel):
    folds: int = Field(5, ge=2, le=20)
    kmax: int = Field(30, ge=1, le=200)
    perm: int = Field(500, ge=10)


def train(k: int) -> KnnPredictor:
    global _model
    model = KnnPredictor(k).fit(load_data())
    MODEL_FILE.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump(model, MODEL_FILE)
    with _lock:
        _model = model
    log.info("Model kNN dilatih ulang: k=%s, %s data", k, model.rows)
    return model


def current_model() -> KnnPredictor:
    global _model
    with _lock:
        if _model is not None:
            return _model
        if MODEL_FILE.is_file():
            try:
                _model = joblib.load(MODEL_FILE)
                return _model
            except Exception as e:  # file rusak / versi scikit-learn berbeda -> latih ulang
                log.warning("Gagal memuat %s (%s), model dilatih ulang", MODEL_FILE, e)
    return train(DEFAULT_K)


def load_data() -> data.Dataset:
    try:
        ds = data.load()
    except Exception as e:
        raise HTTPException(503, f"Database belum siap: {e}") from e
    if ds.n == 0:
        raise HTTPException(503, "Tabel observations kosong. Jalankan php artisan ispu:import dulu.")
    return ds


@app.get("/health")
def health():
    m = _model
    return {"status": "ok", "model_loaded": m is not None, "k": m.k if m else None, "rows": m.rows if m else None}


@app.post("/predict")
def predict(f: Features):
    x = np.array([getattr(f, name) for name in data.FEATURES], dtype=float)
    return current_model().explain(x)


@app.post("/train")
def retrain(req: TrainRequest):
    m = train(req.k)
    return {"k": m.k, "rows": m.rows}


@app.post("/evaluate")
def evaluate_holdout(req: HoldoutRequest):
    ds = load_data()
    if req.test >= ds.n:
        raise HTTPException(422, "Jumlah data uji harus lebih kecil dari jumlah data.")
    return holdout(ds, req.k, req.test)


@app.post("/evaluate-models")
def evaluate_all(req: EvaluateRequest):
    try:
        result = evaluate_models(load_data(), req.folds, req.kmax, req.perm, log=log.info)
    except ValueError as e:
        raise HTTPException(422, str(e)) from e
    train(result["best_k"]["semua"])   # halaman Prediksi langsung memakai k terbaik
    return result
