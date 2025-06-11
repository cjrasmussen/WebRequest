# Image

Simple functions for making some specialized web requests.

## Usage

```php
use cjrasmussen\WebRequest\WebRequest;

// GET THE CONTENTS OF A FILE
$file_contents = WebRequest::getFileContents('https://cjr.dev/feed/');

// GET RESPONSE HEADER FOR A REQUEST
$file_contents = WebRequest::getResponseHeader('https://cjr.dev/');
```

## Installation

Simply add a dependency on cjrasmussen/web-request to your composer.json file if you use [Composer](https://getcomposer.org/) to manage the dependencies of your project:

```sh
composer require cjrasmussen/web-request
```

Although it's recommended to use Composer, you can actually include the file(s) any way you want.


## License

WebRequest is [MIT](http://opensource.org/licenses/MIT) licensed.