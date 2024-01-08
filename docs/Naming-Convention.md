# Naming Convention

The connector uses the following naming convention for its entities:

## Connector

A connector entity in the [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things) ecosystem refers to a entity which is holding basic configuration
and is responsible for managing communication with physical world and other [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things) ecosystem services.

## Device

A device in the [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things) ecosystem refers to a entity which is representing physical device - Viera Televisions.

## Channel

Channel entity is here to separate logical parts of the Viera Televisions like `channels`, `HDMI inputs`, `control actions`

## Property

A property in the [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things) ecosystem refers to a entity which is holding configuration values or
device actual state of a device. Connector, Device and Channel entity has own Property entities.

### Connector Property

Connector related properties are used to store connector state.

### Device Property

Device related properties are used to store configuration like `ip address`, `communication port` or to store basic device information
like `hardware model`, `manufacturer` or `encryption key`. Some of them have to be configured to be able
to use this connector or to communicate with device. In case some of the mandatory property is missing, connector
will log and error.

### Channel Property

Channel related properties are used for storing actual state of Viera Television. It could be `volume state`, `actual output`
or `power state`. This values are read from television and stored in system.

