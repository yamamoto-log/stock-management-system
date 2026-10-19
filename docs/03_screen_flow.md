# 画面遷移図

画面一覧と遷移のルールは [要件定義書 7-3](01_requirements.md) を参照。

## 全体の構成

ログイン後は、共通メニューからどの一覧画面にも移動できる。在庫を変更する6つの操作画面は、共通メニューの「在庫操作」から空の状態で開くか、詳細画面のボタンから入力済みの状態で開く。

```mermaid
flowchart TD
  login[ログイン] --> dash[ダッシュボード]

  subgraph menu[共通メニュー（全画面から移動可）]
    plist[商品一覧]
    blist[箱一覧]
    tlist[移動一覧]
    hist[履歴]
    ops[在庫操作]
    loc[拠点管理 ※管理者]
  end

  dash --> menu

  plist --> pdetail[商品詳細]
  plist --> pedit[商品登録・編集 ※管理者]
  pdetail --> pedit
  blist --> bdetail[箱詳細]
  tlist --> tdetail[移動詳細]
  dash -- 受取待ちの行 --> tdetail

  pdetail <--> bdetail

  subgraph opscreens[在庫操作画面（入力 → 確認 → 完了）]
    sin[入庫]
    sout[出庫]
    adj[在庫調整]
    rel[保管場所変更]
    itr[商品移動（発送）]
    btr[箱ごと移動（発送）]
  end

  ops --> opscreens
  pdetail -- 入力済み --> sin
  pdetail -- 入力済み --> sout
  pdetail -- 入力済み --> rel
  pdetail -- 入力済み --> itr
  bdetail -- 移動元＝この箱 --> rel
  bdetail -- 送る箱＝この箱 --> btr
  tdetail -- 受取済みの明細から --> rel
```

## 画面内で完結する操作

次の操作は別の画面に移らず、その画面の中で確認して完了する。

| 画面 | 操作 |
| --- | --- |
| 箱詳細 | メモの編集、廃止（空の箱のみ） |
| 移動詳細 | 受取確認、取消（受取前のみ） |
| 商品詳細 | 廃番（管理者のみ） |
| 拠点管理 | 登録・編集・停止・再開 |

## 完了後の戻り先

操作画面の完了後は、操作を始めた画面に戻る。共通メニューから開いた場合は、操作の結果が分かる画面（例：入庫なら商品詳細、保管場所変更なら移動先の箱詳細）に移動する。
