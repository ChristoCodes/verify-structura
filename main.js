onSortByChange(newSortBy) {
  const currentPage = this.page;
  this.sortBy = newSortBy;
  this.$nextTick(() => {
    this.page = currentPage;
    this.fetchRowsList();
  });
},
onSortDescChange(newSortDesc) {
  const currentPage = this.page;
  this.sortDesc = newSortDesc;
  this.$nextTick(() => {
    this.page = currentPage;
    this.fetchRowsList();
  });
},
