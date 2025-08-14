import pandas as pd
from typing import Tuple

def predict(mbundle, features: pd.DataFrame) -> Tuple[float, int]:
    """
    返り値: (predicted_yield_kg, predicted_days)
    モデルが未ロードの場合はモックで返す（/readyzは not_ready）。
    """
    if mbundle and mbundle.ok and mbundle.model_yield and mbundle.model_days:
        X = features
        if mbundle.preproc is not None:
            X = mbundle.preproc.transform(features)
        y_kg = float(mbundle.model_yield.predict(X)[0])
        y_days = int(round(float(mbundle.model_days.predict(X)[0])))
        return max(0.0, y_kg), max(0, y_days)
    else:
        # モック（面積×定数、月で日数を出し分け）
        area = float(features.loc[0, "area_m2"] or 0.0)
        month = int(features.loc[0, "transplant_month"] or 6)
        mock_yield = area * (10.0 if 5 <= month <= 9 else 16.0)
        mock_days = 53 if 5 <= month <= 9 else 120
        return mock_yield, mock_days
