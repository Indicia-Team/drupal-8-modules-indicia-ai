# drupal-module-proxy-nia

A plugin for the [API Proxy](https://www.drupal.org/project/api_proxy) module.

It adds authentication for calls to the
[PlantNet API](https://my.plantnet.org/doc).

## Configuration
After installing the module and its dependencies go to Configuration > Web
services > API Proxy and select the Settings tab.

Even if you are going to call the module from the Drupal site hosting the
module, you will have to add the domain of your client in the CORS settings.
Failing to do so results in a 400 Bad Request error. We must tick the box to
enable POST requests too.

In the Authentication section, add the API key that you
will have set up to allow you to access the PlantNet API. Also add the ID
of the classifier which needs to have been added to the warehouse. This is used
to provide traceability of where classifications have come from.

In the Service Path section, add the three configurable parts of the servive
uri.

In the Service Options section, you can choose to receive the raw response
from the classifier in the response from the proxy.

## Permissions
Go to Configuration > People > Permissions and find the section for the API
Proxy. A new permission has been added for using the PlantNet API. Initially,
it is only allowed to the Administrator role. Check the roles who should be
allowed access to the API. If you are not granted permission you will receive a
403 Forbidden response.

## Requests
It is not the intention that you make requests to this module directly. However,
to test the module you can do so by sending a POST request to
`api-proxy/plantnet` relative to the Drupal site hosting the module. A query
string with a parameter `_api_proxy_uri` is required but can be set to any value
as it is overridden by the configuration settings.

The body of the POST is sent as x-www-form-urlencoded. It must contain at least
one element with key, `image[]` having a value which locates an image file. The
value must be the full path to a file uploaded to the interim image folder on
the Drupal server. Use multiple elements for multiple images.

If the body of the POST contains an element with key `raw` and value
`true` then the raw response from the classifier will be included in the
response from the proxy. If the value is `false` then this will prevent such
output, even if enabled in the Service Options configuration.

If you want to add parameters for the service, encode them as JSON and set as
the value for a POST element with key, `params`. Wrap query parameters in a
`query` object and form parameters in a `form` object.
E.g.

```
  params => {
    "form":{
      "organs":["leaf", "flower"]
    },
    "query":{
      "include-related-images":true,
      "nb-results":3
    }
  }
```
The expectation is that the service will be accessed via the indicia_ai
module which appends indicia metadata and filters results.

## Response
The response contains an array of suggested identifications. If no species match
the criteria the array will be empty. A good match will return a single record,
as in the following example.

```
{
  "classifier_id": "20098",
  "classifier_version": "v1",
  "suggestions": [
    "probability": 0.999816358089447,
    "taxon": "Mimas tiliae",
  ]
}
```

An object with key, `raw` will exist if raw classifier output has been
requested.

## Acknowledgement
The image-based plant species identification service used, is based on the
Pl@ntNet recognition API, regularly updated and accessible through the site
https://my.plantnet.org/

![PlantNet Logo](https://user-images.githubusercontent.com/2545390/196983533-2d1bdf51-2112-4621-9c08-afc8a7c50170.png)
