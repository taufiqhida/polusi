"""Model prediksi kNN (scikit-learn): fitur distandarisasi z-score, tetangga dibobot 1/jarak."""
import numpy as np
from sklearn.neighbors import KNeighborsClassifier, KNeighborsRegressor
from sklearn.pipeline import make_pipeline
from sklearn.preprocessing import StandardScaler

from .data import Dataset
from .ispu import round_half_up


def inverse_distance(d: np.ndarray) -> np.ndarray:
    """Bobot tetangga 1/(jarak + 1e-6); +1e-6 agar jarak 0 tidak membagi dengan nol."""
    return 1.0 / (d + 1e-6)


def classifier(k: int):
    return make_pipeline(StandardScaler(), KNeighborsClassifier(n_neighbors=k, weights=inverse_distance))


def regressor(k: int):
    return make_pipeline(StandardScaler(), KNeighborsRegressor(n_neighbors=k, weights=inverse_distance))


class KnnPredictor:
    """Empat model kNN dengan k yang sama: kategori ISPU, tingkat risiko, nilai ISPU, beban ISPA."""

    def __init__(self, k: int = 15):
        self.k = k

    def fit(self, ds: Dataset) -> "KnnPredictor":
        k = min(self.k, ds.n)
        self.category = classifier(k).fit(ds.X, ds.category)
        self.tier = classifier(k).fit(ds.X, ds.tier)
        self.ispu = regressor(k).fit(ds.X, ds.ispu)
        self.burden = regressor(k).fit(ds.X, ds.burden)
        self.rows = ds.n
        return self

    def predict(self, X: np.ndarray) -> dict[str, np.ndarray]:
        return {
            "category": self.category.predict(X),
            "tier": self.tier.predict(X),
            "ispu": round_half_up(self.ispu.predict(X)),
            "burden": self.burden.predict(X),
        }

    def explain(self, x: np.ndarray) -> dict:
        """Prediksi satu input lengkap dengan peluang tiap kelas (untuk halaman Prediksi)."""
        X = x.reshape(1, -1)
        p = self.predict(X)

        def proba(model) -> dict[str, float]:
            pairs = zip(model.classes_, model.predict_proba(X)[0])
            return {str(c): round(float(v), 3) for c, v in sorted(pairs, key=lambda t: -t[1]) if v > 0}

        cat, tier = proba(self.category), proba(self.tier)
        return {
            "ispu": int(p["ispu"][0]),
            "category": next(iter(cat)),
            "category_proba": cat,
            "tier": next(iter(tier)),
            "tier_proba": tier,
            "burden": round(float(p["burden"][0]), 2),
            "neighbors": int(self.category[-1].n_neighbors),
        }
