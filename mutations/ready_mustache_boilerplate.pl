# Drop the first lines of the licence block from the shipped template, leaving the rest of the
# comment: the PHP check cannot see a template, only the Mustache one can.
s/\{\{!\n    This file is part of Moodle - http:\/\/moodle.org\/\n\n    Moodle is free software: you can redistribute it and\/or modify\n    it under the terms of the GNU General Public License as published by\n/{{!\n/;
