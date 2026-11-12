"""Evaluasi model: hold-out sederhana untuk halaman Prediksi dan k-fold CV lengkap untuk halaman Evaluasi."""
import time
from datetime import datetime

import numpy as np
import sklearn
from sklearn.dummy import DummyClassifier, DummyRegressor
from sklearn.inspection import permutation_importance
from sklearn.metrics import (
    accuracy_score,
    confusion_matrix,
    mean_absolute_error,
    mean_squared_error,
    precision_recall_fscore_support,
    r2_score,
)
from sklearn.model_selection import KFold
from sklearn.naive_bayes import GaussianNB
from sklearn.neighbors import NearestNeighbors
from sklearn.pipeline import make_pipeline
from sklearn.preprocessing import StandardScaler
from sklearn.tree import DecisionTreeClassifier, DecisionTreeRegressor

from . import ispu as rumus
from .data import CATEGORIES, FEATURES, WEATHER, Dataset
from .knn import KnnPredictor, classifier, inverse_distance, regressor

SEED = 42
MODELS = {
    "baseline": "Baseline (kelas terbanyak / rata-rata)",
    "nb": "Gaussian Naive Bayes",
    "tree": "Decision Tree (CART, kedalaman 8)",
    "knn": "kNN berbobot jarak",
    "formula": "Rumus resmi ISPU (bukan ML)",
}
CLASS_TARGETS = ["category", "tier"]
REG_TARGETS = ["ispu", "burden"]
FEATURE_KEYS = list(FEATURES)


def now() -> str:
    return datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def r(x, nd=4) -> float:
    return round(float(x), nd)


def classification(y, p, labels) -> dict:
    """Akurasi, macro-F1 (rata-rata kelas yang muncul di data uji), metrik per kelas, confusion matrix."""
    prec, rec, f1, sup = precision_recall_fscore_support(y, p, labels=labels, zero_division=0)
    return {
        "accuracy": accuracy_score(y, p),
        "macro_f1": float(f1[sup > 0].mean()) if (sup > 0).any() else 0.0,
        "per_class": {
            lab: {"precision": r(prec[i]), "recall": r(rec[i]), "f1": r(f1[i]), "support": int(sup[i])}
            for i, lab in enumerate(labels)
        },
        "matrix": confusion_matrix(y, p, labels=labels).tolist(),
    }


def regression(y, p) -> dict:
    return {"mae": mean_absolute_error(y, p), "rmse": mean_squared_error(y, p) ** 0.5, "r2": r2_score(y, p)}


def shuffled(ds: Dataset) -> Dataset:
    return ds.subset(np.random.default_rng(SEED).permutation(ds.n))


# ---------------------------------------------------------------- hold-out (halaman Prediksi)

def holdout(ds: Dataset, k: int, test: int = 1000) -> dict:
    ds = shuffled(ds)
    te, tr = ds.subset(np.arange(test)), ds.subset(np.arange(test, ds.n))
    p = KnnPredictor(k).fit(tr).predict(te.X)
    return {
        "k": k,
        "train": tr.n,
        "test": te.n,
        "category_accuracy": r(accuracy_score(te.category, p["category"])),
        "tier_accuracy": r(accuracy_score(te.tier, p["tier"])),
        "ispu_mae": r(mean_absolute_error(te.ispu, p["ispu"]), 2),
        "burden_mae": r(mean_absolute_error(te.burden, p["burden"]), 3),
        "engine": f"Python scikit-learn {sklearn.__version__}",
        "evaluated_at": now(),
    }


# ---------------------------------------------------------------- k-fold CV (halaman Evaluasi)

def knn_all_k(Xtr, Xte, ytr: dict, kmax: int, labels: dict) -> dict:
    """Prediksi kNN untuk semua k = 1..kmax sekaligus dari satu pencarian tetangga.
    Hasil per k identik dengan KNeighborsClassifier/Regressor(weights=inverse_distance)."""
    scaler = StandardScaler().fit(Xtr)
    nn = NearestNeighbors(n_neighbors=kmax).fit(scaler.transform(Xtr))
    dist, ind = nn.kneighbors(scaler.transform(Xte))
    w = inverse_distance(dist)                      # (n_uji, kmax)
    cw = np.cumsum(w, axis=1)
    out = {}
    for t in CLASS_TARGETS:
        classes = np.array(sorted(labels[t]), dtype=object)
        onehot = (ytr[t][ind][..., None] == classes).astype(float)          # (n_uji, kmax, kelas)
        votes = np.cumsum(w[..., None] * onehot, axis=1)
        out[t] = classes[votes.argmax(axis=2)]                                # (n_uji, kmax)
    for t in REG_TARGETS:
        out[t] = np.cumsum(w * ytr[t][ind], axis=1) / cw
    out["ispu"] = rumus.round_half_up(out["ispu"])
    return out


def evaluate_models(ds: Dataset, folds: int = 5, kmax: int = 30, perm: int = 500, log=print) -> dict:
    started = time.time()
    if ds.n < folds * 10:
        raise ValueError("Data terlalu sedikit. Jalankan php artisan ispu:import dulu.")
    kmax = max(1, min(kmax, int(ds.n * (folds - 1) / folds)))
    truth = {"category": ds.category, "tier": ds.tier, "ispu": ds.ispu, "burden": ds.burden}
    labels = {
        "category": [c for c in CATEGORIES if c in set(ds.category)],
        "tier": sorted(set(ds.tier)),
    }
    splits = list(KFold(n_splits=folds, shuffle=True, random_state=SEED).split(ds.X))

    scenarios = {
        "semua": ("Semua fitur (6 polutan + 4 cuaca)", FEATURE_KEYS),
        "pm25_cuaca": ("PM2.5 + cuaca (sensor terbatas)", ["pm25"] + WEATHER),
        "cuaca": ("Cuaca saja (tanpa sensor polutan)", WEATHER),
    }
    out = {
        "meta": {
            "rows": ds.n, "folds": folds, "kmax": kmax, "seed": SEED,
            "features": {f: FEATURES[f][0] for f in FEATURE_KEYS},
            "engine": f"Python scikit-learn {sklearn.__version__}",
        },
        "scenarios": {
            key: {"label": label, "features": [FEATURES[f][0] for f in feats]}
            for key, (label, feats) in scenarios.items()
        },
        "models": MODELS,
        "distribution": {
            t: {c: int((truth[t] == c).sum()) for c in labels[t]} for t in CLASS_TARGETS
        },
        "best_k": {}, "classification": {"category": {}, "tier": {}}, "regression": {"ispu": {}, "burden": {}},
    }

    # Seberapa cocok kolom ISPU dataset dengan rumus resmi?
    cols = {p: ds.X[:, FEATURE_KEYS.index(p)] for p in rumus.BREAKPOINTS}
    f_ispu, f_dom = rumus.from_pollutants(cols)
    dom_labels = np.where(f_ispu > 0, [FEATURES[d][0] for d in f_dom], "-")
    uniq, counts = np.unique(dom_labels, return_counts=True)
    out["formula_check"] = {
        "match_rate": r(np.mean(np.abs(rumus.round_half_up(f_ispu) - ds.ispu) <= 1)),
        "dominant": {str(u): int(c) for c, u in sorted(zip(counts, uniq), reverse=True)},
    }

    for key, (label, feats) in scenarios.items():
        log(f"Skenario: {label}")
        cols_idx = [FEATURE_KEYS.index(f) for f in feats]
        X = ds.X[:, cols_idx]
        pollutants = [f for f in feats if f in rumus.BREAKPOINTS]
        pred = {m: {} for m in MODELS}
        curve_pred = {t: np.empty((ds.n, kmax), dtype=object if t in CLASS_TARGETS else float)
                      for t in CLASS_TARGETS + REG_TARGETS}
        for m in MODELS:
            for t in CLASS_TARGETS + REG_TARGETS:
                pred[m][t] = np.empty(ds.n, dtype=object if t in CLASS_TARGETS else float)

        for g, (tr, te) in enumerate(splits):
            log(f"  fold {g + 1}/{folds}")
            ytr = {t: truth[t][tr] for t in truth}
            for t in CLASS_TARGETS:
                pred["baseline"][t][te] = DummyClassifier(strategy="most_frequent").fit(X[tr], ytr[t]).predict(X[te])
                pred["nb"][t][te] = make_pipeline(StandardScaler(), GaussianNB()).fit(X[tr], ytr[t]).predict(X[te])
                pred["tree"][t][te] = DecisionTreeClassifier(max_depth=8, min_samples_leaf=5, random_state=SEED) \
                    .fit(X[tr], ytr[t]).predict(X[te])
            for t in REG_TARGETS:
                pred["baseline"][t][te] = DummyRegressor(strategy="mean").fit(X[tr], ytr[t]).predict(X[te])
                pred["tree"][t][te] = DecisionTreeRegressor(max_depth=8, min_samples_leaf=5, random_state=SEED) \
                    .fit(X[tr], ytr[t]).predict(X[te])
            if pollutants:
                f, _ = rumus.from_pollutants({p: X[te, feats.index(p)] for p in pollutants})
                f = rumus.round_half_up(f)
                pred["formula"]["ispu"][te] = f
                pred["formula"]["category"][te] = rumus.category(f)
            for t, v in knn_all_k(X[tr], X[te], ytr, kmax, labels).items():
                curve_pred[t][te] = v

        # ---- Pilih k terbaik: rata-rata macro-F1 kategori dan tingkat risiko ----
        curve = {c: [] for c in ["k", "category_acc", "category_f1", "tier_acc", "tier_f1", "ispu_mae", "burden_mae"]}
        best_k, best_score = 1, -1.0
        for k in range(1, kmax + 1):
            c = classification(truth["category"], curve_pred["category"][:, k - 1], labels["category"])
            t = classification(truth["tier"], curve_pred["tier"][:, k - 1], labels["tier"])
            curve["k"].append(k)
            curve["category_acc"].append(r(c["accuracy"]))
            curve["category_f1"].append(r(c["macro_f1"]))
            curve["tier_acc"].append(r(t["accuracy"]))
            curve["tier_f1"].append(r(t["macro_f1"]))
            curve["ispu_mae"].append(r(mean_absolute_error(truth["ispu"], curve_pred["ispu"][:, k - 1]), 3))
            curve["burden_mae"].append(r(mean_absolute_error(truth["burden"], curve_pred["burden"][:, k - 1])))
            score = (c["macro_f1"] + t["macro_f1"]) / 2
            if score > best_score + 1e-9:
                best_k, best_score = k, score
        out["best_k"][key] = best_k
        if key == "semua":
            out["k_curve"] = curve

        # kNN final dengan k terbaik, memakai estimator scikit-learn
        for tr, te in splits:
            for t in CLASS_TARGETS:
                pred["knn"][t][te] = classifier(best_k).fit(X[tr], truth[t][tr]).predict(X[te])
            for t in REG_TARGETS:
                p = regressor(best_k).fit(X[tr], truth[t][tr]).predict(X[te])
                pred["knn"][t][te] = rumus.round_half_up(p) if t == "ispu" else p

        # ---- Klasifikasi: rata-rata ± simpangan baku antar fold ----
        available = {
            "category": [m for m in MODELS if m != "formula" or pollutants],
            "tier": [m for m in MODELS if m != "formula"],
            "ispu": [m for m in MODELS if m != "nb" and (m != "formula" or pollutants)],
            "burden": [m for m in MODELS if m not in ("nb", "formula")],
        }
        for t in CLASS_TARGETS:
            res = out["classification"][t].setdefault(key, {})
            for m in available[t]:
                scores = [classification(truth[t][te], pred[m][t][te], labels[t]) for _, te in splits]
                acc = np.array([s["accuracy"] for s in scores])
                f1 = np.array([s["macro_f1"] for s in scores])
                res[m] = {"acc": r(acc.mean()), "acc_std": r(acc.std(ddof=1)),
                          "f1": r(f1.mean()), "f1_std": r(f1.std(ddof=1))}

        # ---- Regresi (gabungan semua fold) ----
        for t in REG_TARGETS:
            res = out["regression"][t].setdefault(key, {})
            for m in available[t]:
                res[m] = {k2: r(v) for k2, v in regression(truth[t], pred[m][t].astype(float)).items()}

        # ---- Detail per kelas untuk kNN dengan semua fitur ----
        if key == "semua":
            out["detail"] = {}
            for t in CLASS_TARGETS:
                m = classification(truth[t], pred["knn"][t], labels[t])
                out["detail"][t] = {
                    "labels": labels[t], "matrix": m["matrix"], "per_class": m["per_class"],
                    "accuracy": r(m["accuracy"]), "macro_f1": r(m["macro_f1"]),
                }

    log("Permutation importance")
    out["importance"] = importance(ds, splits[0], out["best_k"]["semua"], perm)
    out["meta"]["evaluated_at"] = now()
    out["meta"]["duration_s"] = round(time.time() - started, 1)
    return out


def importance(ds: Dataset, split, k: int, samples: int, repeats: int = 5) -> dict:
    """Permutation importance kNN (semua fitur, fold pertama): turunnya kinerja saat satu fitur diacak."""
    tr, te = split
    te = te[:samples]
    Xte = ds.X[te]
    models = {
        "category": (classifier(k).fit(ds.X[tr], ds.category[tr]), ds.category[te], "accuracy"),
        "tier": (classifier(k).fit(ds.X[tr], ds.tier[tr]), ds.tier[te], "accuracy"),
        "burden": (regressor(k).fit(ds.X[tr], ds.burden[tr]), ds.burden[te], "neg_mean_absolute_error"),
    }
    base, drops = {}, {}
    for t, (model, y, scoring) in models.items():
        p = model.predict(Xte)
        base[t] = accuracy_score(y, p) if scoring == "accuracy" else mean_absolute_error(y, p)
        drops[t] = permutation_importance(model, Xte, y, scoring=scoring, n_repeats=repeats,
                                          random_state=SEED).importances_mean
    feats = [
        {"feature": FEATURES[f][0], "category_drop": r(drops["category"][j]),
         "tier_drop": r(drops["tier"][j]), "burden_mae_up": r(drops["burden"][j])}
        for j, f in enumerate(FEATURE_KEYS)
    ]
    feats.sort(key=lambda x: -x["tier_drop"])
    return {
        "samples": len(te), "k": k, "repeats": repeats,
        "base": {"category": r(base["category"]), "tier": r(base["tier"]), "burden_mae": r(base["burden"])},
        "features": feats,
    }
