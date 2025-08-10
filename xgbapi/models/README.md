モデルは以下の構成で配置します:

/xgbapi/models/<MODEL_NAME>/<MODEL_VERSION>/
  ├── model_yield.pkl     # 収量予測
  ├── model_days.pkl      # 生育日数予測
  ├── preproc.pkl         # 学習時の前処理パイプライン
  ├── feature_meta.json   # 特徴量定義(任意)
  └── model_card.md       # 由来/精度/注意点(任意)
