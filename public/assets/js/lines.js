// =====================================================================
// 明細の行の追加・削除（入庫・出庫・在庫調整の画面で使う）
//
// 使い方：画面の中に次の3つを用意してから、このファイルを読み込む
//   ・id="lines"          … 明細の行を並べる場所。各行は class="line-row"
//   ・id="line-template"  … 行を追加するときの見本（<template>）
//   ・id="add-line"       … 「＋ 行を追加」のボタン
//   各行の削除ボタンには class="remove-line" を付ける
// =====================================================================

{
  // { } で囲むと、この中の変数は、画面の他の JavaScript とぶつからない
  const lines = document.getElementById("lines");
  const template = document.getElementById("line-template");
  const addLine = document.getElementById("add-line");

  // 明細のない画面（在庫がない拠点など）では、何もしない
  if (lines && template && addLine) {
    // 「＋ 行を追加」：見本を複製して、最後に追加する
    addLine.addEventListener("click", () => {
      lines.appendChild(template.content.cloneNode(true));
    });

    // 削除ボタン：明細全体でクリックを受け止め、押されたのが削除ボタンならその行を消す
    lines.addEventListener("click", (event) => {
      if (!event.target.classList.contains("remove-line")) {
        return;
      }
      const row = event.target.closest(".line-row");

      if (lines.querySelectorAll(".line-row").length > 1) {
        row.remove();
      } else {
        // 最後の1行は消さずに、見本から作った空の行に置き換える
        // （保管場所の初期値「箱なし」なども、見本どおりに戻る）
        row.replaceWith(template.content.cloneNode(true));
      }
    });
  }
}
