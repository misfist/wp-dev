/*jslint white: true */

function WPService($http) {

  var WPService = {
    jsonUrl: 'wp-json/wp/v2/',
    categories: [],
    posts: [],
    pageTitle: 'Latest Posts:',
    currentPage: 1,
    totalPages: 1
  };

  function _updateTitle(documentTitle, pageTitle) {
    document.querySelector('title').innerHTML = documentTitle + ' | AngularJS Demo Theme';
    WPService.pageTitle = pageTitle;
  }

  function _setArchivePage(posts, page, headers) {
    WPService.posts = posts;
    WPService.currentPage = page;
    WPService.totalPages = headers('X-WP-TotalPages');
  }

  WPService.getAllCategories = function() {
    if (WPService.categories.length) {
      return;
    }

    return $http.get( WPService.jsonUrl + 'terms/category/' ).success(function(res){
      WPService.categories = res;
    });
  };

  WPService.getPosts = function(page) {
    return $http.get( WPService.jsonUrl + 'posts/?page=' + page ).success(function(res, status, headers){
      page = parseInt(page, 10);

      if ( isNaN(page) || page > headers('X-WP-TotalPages') ) {
        _updateTitle('Page Not Found', 'Page Not Found');
      } else {
        if (page>1) {
          _updateTitle('Posts on Page ' + page, 'Posts on Page ' + page + ':');
        } else {
          _updateTitle('Home', 'Latest Posts:');
        }

        _setArchivePage(res,page,headers);
      }
    });
  };

  WPService.getSearchResults = function(s) {
    return $http.get( WPService.jsonUrl + 'posts/?filter[s]=' + s + '&filter[posts_per_page]=-1' ).success(function(res, status, headers){
      _updateTitle('Search Results for ' + s, 'Search Results:');

      _setArchivePage(res,1,headers);
    });
  };

  WPService.getPostsInCategory = function(category, page) {
    page = ( ! page ) ? 1 : parseInt( page, 10 );
    _updateTitle('Category: ' + category.name, 'Posts in ' + category.name + ' Page ' + page + ':');

    var request = WPService.jsonUrl + 'posts/?filter[category_name]=' + category.name;
    if ( page ) {
      request += '&page=' + page;
    }

    console.log(request);

    return $http.get(request).success(function(res, status, headers){
      _setArchivePage(res, page, headers);
    });
  };

  return WPService;
}

app.factory('WPService', ['$http', WPService]);