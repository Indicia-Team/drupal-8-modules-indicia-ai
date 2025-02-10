# drupal-module-indicia-ai

A plugin for the [API Proxy](https://www.drupal.org/project/api_proxy) module.

This module provides a uniform interface to any number of AI modules providing
species identification services.

It can forward calls to a specified service or it can use its own logic to
select services in order to obtain the best possible identification.

It augments the responses with taxon information from Indicia.

## Configuration
After installing the module and its dependencies go to Configuration > Web
services > API Proxy and select the Settings tab.

The Classification section allows you to adjust the response sent by the module.
The response will include an array of possible identifications with a
probability value for each.
* You can set a probability threshold so less certain suggestions are filtered
out.
* You can limit the number of suggestions returned.

The Record Clearner section allows you to enable results to be augmented with
the results of a verification check performed by a service of the type
found at https://github.com/BiologicalRecordsCentre/record-cleaner-service.
If enabled you must supply
* The base URL of the service,
* A username for authentication,
* A password for authentication.

## Permissions
Go to Configuration > People > Permissions and find the section for the API
Proxy. A new permission has been added for using the Indicia AI module.
Initially, it is only allowed to the Administrator role. Check the roles who
should be allowed access to the API. If you are not granted permission you will
receive a 403 Forbidden response.

## Requests
To make a request to an image classifier, send a POST request to
`api-proxy/indicia` relative to the Drupal site hosting the module. You must
supply a parameter in the url with key, `_api_proxy_uri` having a value which is
used to determine which classifier to forward the request to. The following
values are supported
* `/` allows the module to determine which classifier to call. Currently only
NIA is supported.
* `nia` specifies the Nature Identification API.
* `plantnet` specifies the PlantNet API
To use these APIs, ensure you have enabled and configured the corresponding
modules.

The body of the POST must contain an element with key, `image` and a value which
locates an image file. It can be
* the name of a file uploaded to the interim image folder on the Drupal server,
* a url to a web-accessible image.

Any parameters for the classifier can be sent in JSON format in an element
with key, `params`. The parameters should be in one of two child objects, `form`
and `query` depending on whether they are to be included as form data or in the
query string.

In order to match the classifier results against an indicia species list, you
must include a POST argument with key `list` and value of the taxon_list_id of
the list you want to match against.

The body of the POST may also contain any number of elements with the key,
`groups[]`, each with an integer value corresponding to a taxon_group_id used
by indicia taxon list. This can be used for limiting results to certain groups.

If a check against Record Cleaner rules is wanted then additional parameters
must be supplied
* `date`, the date of the observation, typically in d/m/yyyy format
* `sref`, the spatial reference of the observation in json format, e.g.
`{"srid":4326,"latitude":54.1,"longitude":-2.3,"accuracy":100}`
* `org_group_rules_list`, optional, in json format, limits the rule checks to
those provided by specific organisations and groups. Can also constrain the
types of rule checks. E.g.

```
[{
  "organisation": "Botanical Society of Britain and Ireland",
  "group": "BSBI",
  "rules": ["tenkm"]
}]
```
Further information can be obtained from the /docs page of the service, e.g.
https://record-cleaner.brc.ac.uk/docs

# Response
The response includes an array of suggested identifications. If no species match
the criteria the array will be empty. A good match will return a single record
as in the following example.

```
{
  "classifier_id": "20098",
  "classifier_version": "v1",
  "suggestions": [
    {
      "probability": 0.999816358089447,
      "taxon": "Mimas tiliae",
      "taxa_taxon_list_id": "257439",
      "taxon_group_id": "114"
    }
  ]
}
```
The classifier_id, classifier_version, and probability, come from the
classifier. The other fields are from the indicia warehouse lookup and are the
preferred taxon name and preferred taxa_taxon_list_id . The
latter are absent if no look up is required or there is an error in the
warehouse response. For example, if the classifer returns a taxon name which
cannot be matched to a name in the Indicia species list then the Indicia fields
will be absent.

If the Record Cleaner check is enabled then each suggestion contains an
additional `record_cleaner` field. This has the following possible values:
* `error`, A fault communicating with the service, e.g. if the service is
down or the password is incorrect.
* `invalid`, A fault in the input parameters, e.g. a number where a string is
expected.
* `omit`, Input parameters have been omitted.
* `warn`, A warning from the service, e.g. there are no rules for the taxon.
* `fail`, A test performed by the service failed, e.g. the location is outside
the known range of the taxon.
* `pass`, All tests performed by the service were successful.
