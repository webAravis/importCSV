# ImportCSV module for Thelia 2 #

This module will import a CSV database into the local Thelia 2 database. The following information will be imported :

- The complete catalog, with images and documents, features and attributes.

**Be aware that the related content of your database will be deleted, so be sure to backup it before starting the importation process.**

It is **recommended** to start the import process on a fresh Thelia 2 database, to prevent any inconsistencies

## How to install

This module must be into your ```modules/``` directory (thelia/local/modules/).

You can download the .zip file of this module or clone it into your project like this :

```
cd /path-to-thelia
git clone https://github.com/webAravis/importCSV.git local/modules/ImportCSV
```

Next, go to your Thelia admin panel for module activation.