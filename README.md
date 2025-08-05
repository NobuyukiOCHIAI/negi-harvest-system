# Negi Harvest System

水耕栽培小ねぎの栽培管理・収穫登録・予測システム。

## 必要環境
- PHP 7.4+
- MySQL 5.7+ または MariaDB 10+

## セットアップ手順
1. `sql/schema.sql` と `sql/sample_data.sql` をインポート
2. `db.php.sample` を `db.php` にリネームし、接続情報を設定
3. `/data_entry/harvest.php` にブラウザでアクセス

## ディレクトリ構成
```
negi-harvest-system/
├── README.md
├── .gitignore
├── db.php.sample
├── sql/
│   ├── schema.sql
│   └── sample_data.sql
└── data_entry/
    ├── harvest.php
    ├── get_cycle_history.php
    ├── get_beds.php
    └── get_loss_types.php
```
