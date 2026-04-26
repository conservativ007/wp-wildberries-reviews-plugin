if (typeof wb_reviews_error !== "undefined" && wb_reviews_error.message) {
  console.error("[WB Reviews]", wb_reviews_error.message);
}

if (typeof wb_reviews_debug !== "undefined") {
  console.group("[WB Reviews] Debug");
  console.table(wb_reviews_debug);
  console.groupEnd();
}
