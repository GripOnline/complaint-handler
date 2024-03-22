# Complaints-mail processing script

This script is able to process complaint mails to automatically unsubscribe users if a 'list-unsubscribe' header is found.

This is useful if you send bulk email and want to keep your mailing list clean. Some large mail providers (Hotmail / Live / Yahoo / AOL) can send an email every time someone reports a mail as spam. We use this script to automate this Feedback-Loop (FBL). The script checks if there was a 'list-Unsubscribe' header in the original mail and either uses a mailto- or POST method to unsubscribe the user. We check if the mailto address or POST link seems to be one of our customers. So if you want to use this script you might want to change those checks.

# Setup

The project has dependencies that can be installed using Composer:

```sh
$ composer update
```

In order to have all the mail for a specific user piped to this script, add a '.forward' file in the user's home dir (including quotes):

```txt
"|/path/to/php /path/to/process_message.php"
```

# Usage

Incoming message will either end up in messages/processed or messages/unknown (relative to the script). Logs will be written to complaints.log in the same dir as the script. Make sure the user has sufficient rights to write to these locations.
