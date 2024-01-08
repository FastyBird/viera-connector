# Configuration

To use Panasonic Viera televisions with the [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things) ecosystem, you will need to configure at least one connector.
The connector can be configured using the [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things) user interface or through the console.

## TV setup

Before you could pair your television with connector, you have to prepare your television.

On your TV go to `Menu -> Network -> TV Remote App Settings` and make sure that the following settings are all turned ON:

* TV Remote
* Powered On by Apps
* Networked Standby

Then, go to `Menu -> Network -> Network Status -> Status Details` and take note of your TV ip address.

## Configuring the Connectors and Televisions through the Console

To configure the connector through the console, run the following command:

```shell
php bin/fb-console fb:viera-connector:install
```

> [!NOTE]
The path to the console command may vary depending on your FastyBird application distribution. For more information, refer to the FastyBird documentation.

This command is interactive and easy to operate.

The console will show you basic menu. To navigate in menu you could write value displayed in square brackets or you
could use arrows to select one of the options:

```
Viera connector - installer
===========================

 ! [NOTE] This action will create|update|delete connector configuration

 What would you like to do? [Nothing]:
  [0] Create connector
  [1] Edit connector
  [2] Delete connector
  [3] Manage connector
  [4] List connectors
  [5] Nothing
 > 0
```

### Create connector

If you choose to create a new connector, you will be asked to provide a connector identifier and name:

```
 Provide connector identifier:
 > my-viera
```

```
 Provide connector name:
 > My Viera
```

After providing the necessary information, your new Panasonic Viera connector will be ready for use.

```
 [OK] New connector "My Viera" was successfully created
```

### Create television

After new connector is created you will be asked if you want to create new television:

```
 Would you like to configure connector television(s)? (yes/no) [yes]:
 > 
```

Or you could choose to manage connector televisions from the main menu.

Now you will be asked to provide some television details:

```
 Provide television IP address:
 > 10.10.0.2
```

```
 Would you like to configure HDMI inputs? (yes/no) [no]:
 > y
```

If you want to configure HDMI inputs you could define one, two or all inputs what you television have

```
 ! [NOTE] Now you have to provide name for configured HDMI input and its number. HDMI number is related to you
 !        television

 Provide name for HDMI input:
 > HDMI 1
```

```
 Provide number for "HDMI 1" HDMI input:
 > 1
```

> [!TIP]
This number is related to HDMI port on Viera Panasonic. This number could be found on sticker near the physical port or in
the input menu of the Television

```
 Would you like to configure another HDMI input? (yes/no) [no]:
 > n
```

> [!NOTE]
You could configure all or only some HDMI inputs.

```
 Would you like to configure television MAC address? (yes/no) [no]:
 > y
```

> [!NOTE]
MAC address will be used for Wake on Lan action which will turn you television on.
Some older models does not support to turn on action, therefore MAC address have to be configured

```
 Provide television MAC address in format: 01:23:45:67:89:ab:
 > 28:24:ff:38:5e:27
```

If there are no errors, you will receive a success message.

```
 [OK] Television "49DX600_Series" was successfully created
```

If you television require PIN code authentication you will be asked to provide pin which will be showed on you television screen

```
 Provide television PIN code displayed on you TV:
 > 1234
```

When valid PIN code is provided, pairing will be successfully finished.

### Connectors and Televisions management

With this console command you could manage all your connectors and their televisions. Just use the main menu to navigate to proper action.

## Configuring the Connector with the FastyBird User Interface

You can also configure the Panasonic Viera connector using the [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things) user interface. For more information on how to do this,
please refer to the [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things) documentation.
