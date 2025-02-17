# crossref
Crossref plugin for OMP 3.4

## Description
Based on the Crossref plugin for OJS 3.4, this is a Crossref plugin for OMP 3.4.
This plugin allows to register DOIs for monographs and their chapters with crossref using HTTP.

## Using the Plugin
This plugin, similar to the Crossref plugin for OJS 3.4, is a general-type plugin seamlessly integrated into the updated DOI structure.

### Installation
Install the plugin via the editor backend or move it to the folder "/plugins/general/" and use the CLI to install via the command "php tools/upgrade.php upgrade". More information about how to install plugins can be found here: https://docs.pkp.sfu.ca/learning-ojs/en/settings-website#external-plugins

### Usage
After installation, the plugin will set up a settings form for the registration agency. The form can be found under "distribution":
![image](https://github.com/user-attachments/assets/ac468b5f-bc21-4d6a-b7af-cb7bdab6599a)

Please enter your Crossref credentials for the deposit via HTTP.

Under DOIs you can now export XMLs (if multiple XMLs are ticked, they will be packaged into a tar.gz.) and deposit to Crossref.

### Monographs and Chapters
The XMLs are constructed according to the following resources:
https://www.crossref.org/documentation/schema-library/required-recommended-elements/#003 as well as
https://www.crossref.org/documentation/schema-library/markup-guide-record-types/books-and-chapters/

Feel free to contribute to this plugin! :-)
