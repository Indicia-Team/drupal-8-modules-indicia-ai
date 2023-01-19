# drupal-module-proxy-nia

A plugin for the [API Proxy](https://www.drupal.org/project/api_proxy) module.

It adds authentication for calls to the 
[Waarneming API](https://waarneming.nl/api/v1/docs/) In particular, it is 
intended for accessing the image classifier.

The Waarneming API is, in turn, providing a proxy service to the [Nature
Identification API](https://identify.biodiversityanalysis.nl/)

## Configuration
After installing the module and its dependencies go to Configuration > Web 
services > API Proxy and select the Settings tab.

Even if you are going to call the module from the Drupal site hosting the
module, you will have to add the domain of your client in the CORS settings.
Failing to do so results in a 400 Bad Request error. We must tick the box to
enable POST requests too.

In the Authentication section, add the client ID, email and password that you
will have set up which allow you to access the Waarneming API. Also add the ID
of the classifier which needs to have been added to the warehouse. This is used
to provide traceability of where classifications have come from.

In the Service Path section, add the three configurable parts of the servive 
uri.

## Permissions
Go to Configuration > People > Permissions and find the section for the API
Proxy. A new permission has been added for using the NIA API. Initially,
it is only allowed to the Administrator role. Check the roles who should be
allowed access to the API. If you are not granted permission you will receive a
403 Forbidden response.

## Requests
It is not the intention that you make requests to this module directly. However,
to test the module you can do so by sending a POST request to `api-proxy/nia`
relative to the Drupal site hosting the module. A query string with a parameter
`_api_proxy_uri` is required but can be set to any value as it is overridden by
the configuration settings.

The body of the POST must contain an element with key, `image` and a value which
locates an image file. It must be the full path to a file uploaded to the 
interim image folder on the Drupal server. Send it as x-www-form-urlencoded.

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
