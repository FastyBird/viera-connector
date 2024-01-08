
# Televisions Discovery

The Panasonic Viera connector includes a built-in feature for automatic televisions discovery. This feature can be triggered manually
through a console command or from the [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things) user interface.

## Manual Console Command

To manually trigger televisions discovery, use the following command:

```shell
php bin/fb-console fb:viera-connector:discover
```

> [!NOTE]
The path to the console command may vary depending on your FastyBird application distribution. For more information, refer to the FastyBird documentation.

The console will prompt for confirmation before proceeding with the discovery process.

```
Viera connector - discovery
===========================

 ! [NOTE] This action will run connector televisions discovery.

 Would you like to continue? (yes/no) [no]:
 > y
```

You will then be prompted to select the connector to use for the discovery process.

```
 Would you like to discover televisions with "My Viera" connector (yes/no) [no]:
 > y
```

The connector will then begin searching for new Panasonic Viera televisions, which may take a few minutes to complete. Once finished,
a list of found televisions will be displayed.

```
 [INFO] Starting Viera connector discovery...


[============================] 100% 1 min, 44 secs/1 min, 44 secs


 [INFO] Stopping Viera connector discovery...



 [INFO] Found 2 new televisions


+---+--------------------------------------+----------------+------------------------------+------------+------------+
| # | ID                                   | Name           | Model                        | IP address | Encryption |
+---+--------------------------------------+----------------+------------------------------+------------+------------+
| 1 | 10513b70-944c-4a90-aa37-b5a08ffdcac4 | 65FX700_Series | Panasonic VIErA TX-65FX700E  | 10.10.0.6  | yes        |
| 2 | 6ed9d4ae-45b3-4081-a14f-e48f1f49dc30 | 49DX600_Series | Panasonic VIErA TX-49DX600EA | 10.10.0.75 | no         |
+---+--------------------------------------+----------------+------------------------------+------------+------------+

 [INFO] Some televisions require to by paired to get encryption keys

 Would you like to pair this televisions? (yes/no) [no]:
 > y
```

Now that all newly discovered televisions have been found, they are available in the [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things) system and can be utilized.

If some televisions require PIN code authentication you will be prompted if you want to pair these televisions. If you do not finish this pairing you will not be able to fully control this televisions.

```
 [INFO] Pairing television: 65FX700_Series
```

Your television have to be turned on and on the screen you will find (in bottom left corner) pairing PIN code.

```
 Provide television PIN code displayed on you TV:
 > 1234
```

```
 [OK] Television 65FX700_Series was successfully paired

 [OK] Televisions discovery was successfully finished
```