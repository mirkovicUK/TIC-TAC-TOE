# Requirements Document

## Introduction

Remote Tic-Tac-Toe is a web application that lets two people play tic-tac-toe against each other from separate devices. One player creates a game and shares a join code; the second player joins with that code. The two players then alternate moves, each seeing the other's move without manually refreshing. The application signals when a game has ended by a win or a draw, and lets the same two players continue into a subsequent game.

The application has no user accounts. A player's right to move in a specific game is carried by a per-game, per-mark token held in that player's browser session. Authorisation of every move is therefore a first-class requirement rather than an afterthought.

These requirements are written for a deliberately small deliverable: a public git repository, a hosted running instance, and documentation sufficient to run the application and its tests. Requirements are stated in terms of observable system behaviour and are intended to remain implementation-agnostic.

### Out of Scope

The following are explicit non-goals. They are recorded here so that their absence is understood as a scoping decision rather than an omission.

- User accounts, registration, passwords, or persistent player profiles
- Chat, emotes, or any player-to-player messaging
- Leaderboards, ratings, match history browsing, or statistics
- Spectators or any third-party access to a Game, whether read-only or read-write
- Recovery of access to a Game after loss of the Player_Session, such as cleared cookies, a different browser, or a different device; there are no user accounts to re-authenticate against, so a lost Player_Session is an accepted and documented limitation
- Computer or AI opponents
- Board sizes other than 3x3, or more than two players per game
- Forfeit timers, turn clocks, or automatic resignation on inactivity
- Real-time transport such as WebSockets (state synchronisation is poll-based)
- A record of the individual prompts issued to the AI tooling; reproducing the instructions given across a working session was judged disproportionate to the stated time budget, so the corrections made to the generated output are recorded instead, those being the part that evidences review of the output rather than merely its production

## Glossary

- **Application**: The complete deployed system, comprising the Web_Client, the Game_Service, and the Rules_Engine.
- **Web_Client**: The browser-side component that renders the board and submits player actions.
- **Game_Service**: The server-side component that owns persistence, authorisation, rate limiting, logging, and the lifecycle of a Game.
- **Rules_Engine**: The framework-independent server-side component that derives Board occupancy, the Mark_To_Move, and the Outcome from a Move_List. The Rules_Engine holds no state of its own.
- **Game**: A single instance of a tic-tac-toe match between two Players, identified by a Game_Id.
- **Game_Id**: The opaque, durable identifier assigned to a Game when that Game is created, used as the URL key for that Game and as one half of the pair to which every Player_Token for that Game is bound.
- **Board**: The 3x3 grid on which a Game is played.
- **Cell**: One of the nine positions on the Board, addressed by an integer Cell_Index in the range 0 to 8 inclusive.
- **Mark**: One of the two values `X` or `O`.
- **Player**: One of the two humans participating in a Game. Each Player is associated with exactly one Mark within that Game.
- **Creator**: The Player who created the Game and is assigned the Mark `X` in that Game.
- **Joiner**: The Player who joined an existing Game using a Join_Code and is assigned the Mark `O` in that Game.
- **Join_Code**: A shareable string that identifies a Game for the purpose of joining it.
- **Join_Link**: A URL containing the Join_Code that opens the Application at the join action for the corresponding Game.
- **Player_Token**: A secret value issued by the Game_Service and bound to exactly one pair of (Game_Id, Mark), held by the Web_Client in the Player_Session and presented with every state-changing request.
- **Player_Session**: The browser-scoped store holding a Player's Player_Tokens, implemented as a server-side session or a signed cookie.
- **Move**: The placement of a Mark in a Cell, recorded as a Cell_Index together with a Sequence_Index. The Mark of a Move is derived from the parity of that Move's Sequence_Index and is not stored.
- **Sequence_Index**: The zero-based ordinal position of a Move within a Game, forming a strictly increasing sequence with no gaps.
- **Move_List**: The ordered list of Moves recorded for a Game, which is the complete record of that Game.
- **Well_Formed_Move_List**: A Move_List in which every Cell_Index is an integer in the range 0 to 8 inclusive, no Cell_Index appears more than once, the length is at most nine, the Sequence_Indexes form a strictly increasing sequence from zero with no gaps, and no Move follows a Move that completes a Winning_Line.
- **Game_State**: The persisted lifecycle value of a Game; exactly one of `waiting_for_opponent`, `active`, `won`, or `drawn`.
- **Terminal_State**: A Game_State of `won` or `drawn`.
- **Winning_Line**: One of the eight sets of three Cells (three rows, three columns, two diagonals) that constitute a win when all three are occupied by the same Mark.
- **Outcome**: The result derived by the Rules_Engine from a Move_List; exactly one of `in_progress`, `won_by_X`, `won_by_O`, or `drawn`.
- **Rematch**: A new Game created from a Game in a Terminal_State, carrying both Players forward.
- **Version_Counter**: A monotonically increasing integer associated with a Game, having the value zero when that Game is created and increasing by exactly one for each committed state-changing operation on that Game, whatever number of observable changes that operation produces.
- **Eligible_For_Expiry**: The condition of a Game that has passed a retention threshold stated in criterion 1 or criterion 2 of Requirement 13, and that the command required by criterion 3 of Requirement 13 therefore deletes on its next run.
- **Expiry_Record**: The minimal record retained after a Game has been deleted under Requirement 13, holding the Game_Id of that Game and the time at which that Game was deleted, and holding no Move_List, no Join_Code, and no Player_Token.
- **Health_Endpoint**: The unauthenticated endpoint that reports whether the Application is able to serve requests.
- **Rate_Limit_Subject**: The subject against which a request is counted for rate limiting; the Player_Session where the request carries a Player_Session, and the requesting IP address where the request carries no Player_Session.

## Requirements

### Requirement 1: Create a Game

**User Story:** As a player, I want to create a game and receive a shareable code, so that I can invite one other person to play with me from their own device.

#### Acceptance Criteria

1. WHEN a visitor submits the create-game action, THE Game_Service SHALL create a Game with a Game_State of `waiting_for_opponent` and an empty Move_List.
2. WHEN a Game is created, THE Game_Service SHALL assign that Game a non-sequential and non-guessable Game_Id generated from a cryptographically secure random source or a time-ordered random source, and SHALL derive no part of that Game_Id from a monotonically increasing database sequence.
3. WHEN a Game is created, THE Game_Service SHALL generate a Join_Code using a cryptographically secure random source with at least 48 bits of entropy.
4. THE Game_Service SHALL assign at most one Game to any given Join_Code.
5. WHEN a Game is created, THE Game_Service SHALL assign the Mark `X` to the Creator and issue a Player_Token bound to that Game_Id and the Mark `X`.
6. WHEN a Game is created, THE Web_Client SHALL display the Join_Code and a Join_Link for that Game.
7. WHILE a Game has a Game_State of `waiting_for_opponent`, THE Web_Client SHALL display the Join_Code and an indication that the Application is waiting for a second Player.

### Requirement 2: Join a Game

**User Story:** As a second player, I want to join a game using the code I was sent, so that we can start playing.

#### Acceptance Criteria

1. WHEN a visitor submits a Join_Code that matches a Game with a Game_State of `waiting_for_opponent`, THE Game_Service SHALL assign the Mark `O` to that visitor, issue a Player_Token bound to that Game_Id and the Mark `O`, and set the Game_State to `active`.
2. IF a submitted Join_Code matches no Game, THEN THE Game_Service SHALL reject the join action and THE Web_Client SHALL display a message stating that the Join_Code was not recognised.
3. IF a submitted Join_Code matches a Game that already has two Players and the requesting Player_Session holds no Player_Token for that Game, THEN THE Game_Service SHALL reject the join action and THE Web_Client SHALL display a message stating that the Game is full.
4. WHEN a Player_Session holding a Player_Token for a Game requests that Game, THE Game_Service SHALL return that Game with the Mark bound to the presented Player_Token, without creating an additional Player.
5. WHEN the Creator's Player_Session submits the Join_Code of the Creator's own Game, THE Game_Service SHALL return that Game with the Mark `X` and SHALL leave the Game_State unchanged.
6. WHEN a Game_State changes from `waiting_for_opponent` to `active`, THE Game_Service SHALL increment the Version_Counter for that Game.
7. WHEN two or more join requests for the same Game are processed concurrently and that Game has a Game_State of `waiting_for_opponent`, THE Game_Service SHALL assign the Mark `O` to exactly one of those requests and SHALL reject every other such request with the same outcome that criterion 3 of this requirement states for a Game that already has two Players.

### Requirement 3: Player Identity and Move Authorisation

**User Story:** As a player, I want only my opponent and me to be able to place marks in our game, so that a third party holding the join code cannot play my moves for me.

#### Acceptance Criteria

1. THE Game_Service SHALL bind each issued Player_Token to exactly one pair of (Game_Id, Mark).
2. THE Game_Service SHALL derive the acting Mark for every move request solely from the Player_Token presented with that request.
3. IF a move request presents no Player_Token for the target Game, THEN THE Game_Service SHALL reject the request with a not-authorised outcome that is distinct from the not-your-turn outcome, irrespective of whether that request would otherwise be a valid Move.
4. IF a move request presents a Player_Token bound to a Game_Id other than the target Game_Id, THEN THE Game_Service SHALL reject the request with a not-authorised outcome.
5. IF a move request presents a valid Player_Token whose bound Mark differs from the Mark_To_Move, THEN THE Game_Service SHALL reject the request with a not-your-turn outcome that is distinct from the not-authorised outcome.
6. WHEN a move request includes a Mark value in its payload, THE Game_Service SHALL ignore that value and use the Mark bound to the presented Player_Token.
7. WHEN a visitor requests a Game for which the Player_Session holds no valid Player_Token, THE Web_Client SHALL present only the indication that the viewer is not a Player in that Game, and SHALL present no Board, Move_List, Game_State, or Mark_To_Move for that Game.
8. THE Game_Service SHALL issue Player_Tokens with at least 128 bits of entropy from a cryptographically secure random source.
9. THE Game_Service SHALL evaluate the authorisation of a move request before evaluating any move-validity condition of that request, and SHALL report only the not-authorised outcome for a request that fails authorisation.
10. WHERE a request presents no valid Player_Token for the target Game, THE Game_Service SHALL exclude the Board, the Move_List, the Game_State, and the Mark_To_Move of that Game from the response, with the single exception of a join action that is accepted under Requirement 2, criterion 1, and therefore issues a Player_Token to the requesting Player_Session.

### Requirement 4: Turn Taking and Move Validity

**User Story:** As a player, I want the rules of tic-tac-toe enforced by the server, so that neither player can make an illegal move.

#### Acceptance Criteria

1. THE Rules_Engine SHALL report the Mark_To_Move as `X` for an even Move_List length and as `O` for an odd Move_List length.
2. WHEN the Game_Service accepts a Move, THE Game_Service SHALL record that Move with a Sequence_Index equal to the length of the Move_List before acceptance.
3. IF a move request targets a Cell that is already occupied in the Move_List, THEN THE Game_Service SHALL reject the request with an invalid-move outcome and SHALL leave the Move_List unchanged.
4. IF a move request targets a Cell_Index outside the range 0 to 8 inclusive, or a value that is not an integer, THEN THE Game_Service SHALL reject the request with an invalid-move outcome and SHALL leave the Move_List unchanged.
5. IF a move request targets a Game with a Game_State of `waiting_for_opponent`, THEN THE Game_Service SHALL reject the request with a game-not-started outcome and SHALL leave the Move_List unchanged.
6. IF a move request targets a Game in a Terminal_State, THEN THE Game_Service SHALL reject the request with a game-ended outcome and SHALL leave the Move_List unchanged.
7. WHEN the Game_Service accepts a Move, THE Game_Service SHALL increment the Version_Counter for that Game.

### Requirement 5: Concurrent Move Submission

**User Story:** As a player, I want simultaneous submissions to resolve to a single agreed board, so that the two of us never see different games.

#### Acceptance Criteria

1. THE Game_Service SHALL enforce uniqueness of Sequence_Index within a Game as a persisted invariant.
2. THE Game_Service SHALL enforce uniqueness of Cell_Index within a Game as a persisted invariant.
3. WHERE two move requests for the same Game each satisfy every authorisation condition of Requirement 3 and every move-validity condition of Requirement 4 when evaluated against the state of that Game observed by that request, WHEN those two requests are processed concurrently and both target the same Sequence_Index, THE Game_Service SHALL accept exactly one of the two requests.
4. IF a move request is rejected because another Move has already been recorded at the target Sequence_Index, THEN THE Game_Service SHALL return a conflict outcome together with the current Game_State, Move_List, and Version_Counter.
5. WHEN the Web_Client receives a conflict outcome, THE Web_Client SHALL render the Game state returned with that outcome.
6. THE Game_Service SHALL record at most nine Moves for any Game.

### Requirement 6: End-of-Game Signalling

**User Story:** As a player, I want to be told clearly when the game has ended and how it ended, so that we both know the result without inspecting the board ourselves.

#### Acceptance Criteria

1. THE Game_Service SHALL assign every Game exactly one Game_State from the set `waiting_for_opponent`, `active`, `won`, `drawn`.
2. WHEN an accepted Move completes any Winning_Line with a single Mark, THE Game_Service SHALL set the Game_State to `won` and SHALL record the winning Mark.
3. WHILE a Game has a Game_State of `won`, THE Game_Service SHALL include the winning Mark and the Cells of every completed Winning_Line in the representation of that Game that it returns to a Player of that Game.
4. WHEN an accepted Move results in a Move_List of nine Moves that completes no Winning_Line, THE Game_Service SHALL set the Game_State to `drawn`.
5. WHILE a Game has a Game_State of `won`, THE Web_Client SHALL display the winning Mark and the Cells forming every completed Winning_Line to both Players.
6. WHILE a Game has a Game_State of `drawn`, THE Web_Client SHALL display that the Game ended in a draw to both Players.
7. WHILE a Game has a Game_State of `active`, THE Web_Client SHALL display which Mark is the Mark_To_Move and whether that Mark belongs to the viewing Player.

### Requirement 7: Subsequent Game

**User Story:** As a player, I want to start another game with the same opponent once ours has finished, so that we can keep playing without exchanging a new code.

#### Acceptance Criteria

1. WHILE a Game is in a Terminal_State, THE Web_Client SHALL present a control that requests a Rematch to both Players.
2. WHEN a Player holding a Player_Token for a Game in a Terminal_State requests a Rematch and no Rematch exists for that Game, THE Game_Service SHALL create a new Game with an empty Move_List and a Game_State of `active`.
3. WHEN the Game_Service creates a Rematch, THE Game_Service SHALL assign the Mark `X` to the Player who held the Mark `O` in the preceding Game and the Mark `O` to the Player who held the Mark `X` in the preceding Game, irrespective of the connection state of either Player at the moment the Rematch is created and irrespective of which Player requested the Rematch first.
4. WHEN the Game_Service creates a Rematch, THE Game_Service SHALL record on that Rematch the Game_Id of the preceding Game from which that Rematch was created.
5. WHEN the Game_Service creates a Rematch, THE Game_Service SHALL increment the Version_Counter of the preceding Game.
6. WHEN a Player_Session presents a valid Player_Token for a preceding Game and requests the Rematch of that Game, THE Game_Service SHALL issue that Player_Session, at the time of that request, a Player_Token bound to the Game_Id of that Rematch and to the Mark assigned to that Player by criterion 3 of this requirement.
7. THE Game_Service SHALL establish continuity of Player identity across a Rematch solely by the Player_Token held for the preceding Game.
8. THE Game_Service SHALL associate at most one Rematch with any given Game.
9. WHEN a Player holding a Player_Token for a Game requests a Rematch for that Game and a Rematch is already associated with that Game, THE Game_Service SHALL return that existing Rematch and SHALL create no additional Game.
10. IF a Rematch is requested for a Game that is not in a Terminal_State, THEN THE Game_Service SHALL reject the request with an invalid-state outcome.
11. IF a Rematch is requested by a Player_Session holding no Player_Token for the preceding Game, THEN THE Game_Service SHALL reject the request with a not-authorised outcome.
12. WHILE a Rematch is associated with a Game, THE Game_Service SHALL include the Game_Id of that Rematch in the representation of that Game returned to a Player of that Game.
13. WHEN a Rematch exists for a Game that a Player is viewing, THE Web_Client SHALL offer that Player navigation to the Rematch.
14. THE Game_Service SHALL retain the Move_List of a Game after a Rematch is created from that Game.
15. WHERE a Rematch request presents a valid Player_Token for a preceding Game that is in a Terminal_State and has not been deleted under Requirement 13, THE Game_Service SHALL return a Rematch for that Game, being either the Rematch it creates or the Rematch already associated with that Game.

### Requirement 8: State Synchronisation

**User Story:** As a player, I want my opponent's move to appear on my screen on its own, so that I do not have to refresh the page to find out whether it is my turn.

#### Acceptance Criteria

1. WHERE the Player_Session holds a valid Player_Token for a Game, WHILE that Game is not in a Terminal_State, THE Web_Client SHALL request the state of that Game at intervals of no more than 2 seconds.
2. WHEN a Move is accepted for a Game, THE Web_Client of the other Player SHALL render the resulting Board within 3 seconds of that acceptance.
3. THE Game_Service SHALL include the Version_Counter of a Game in every response describing that Game.
4. WHERE a state request presents a valid Player_Token for the target Game, THE Game_Service SHALL return the current Game_State, Move_List, Mark_To_Move, and Version_Counter of that Game, irrespective of any Version_Counter value presented with that request.
5. WHERE the Player_Session holds a valid Player_Token for a Game, WHILE that Game is in a Terminal_State and no Rematch is associated with that Game, THE Web_Client SHALL request the state of that Game at intervals of no more than 5 seconds.
6. WHEN a Rematch is discovered for a Game, or the viewing Player navigates away from a Game, THE Web_Client SHALL stop requesting the state of that Game.
7. THE Game_Service SHALL exclude Player_Token values from every Game state response.

### Requirement 9: Abandonment

**User Story:** As a player, I want the game to still be there when I come back, and to be able to tell when my opponent has gone quiet, so that a dropped connection does not silently destroy the game.

#### Acceptance Criteria

1. THE Game_Service SHALL persist every Game and its Move_List durably, so that a Game survives a restart of the Application.
2. WHEN a Player_Session holding a valid Player_Token requests a previously created Game that has not been deleted under Requirement 13, THE Game_Service SHALL return the current state of that Game irrespective of the time elapsed since the last accepted Move.
3. WHILE a Game has a Game_State of `active` and the Mark_To_Move does not belong to the viewing Player, THE Web_Client SHALL display an indication that the Application is waiting for the opponent to move.
4. WHILE a Game has a Game_State of `active`, the Mark_To_Move does not belong to the viewing Player, at least one Move has been accepted, and no Move has been accepted for at least 60 seconds, THE Web_Client SHALL display an indication that the opponent may have stopped playing.

   The "at least one Move has been accepted" clause narrows this criterion and was added deliberately. The elapsed time is measured from the most recent accepted Move, which the Web_Client receives as `lastMoveAt`; that value is absent for a Game whose Move_List is empty, and the only other origin — when the Game became `active` — is not part of the representation. Without the clause the criterion would be read as vacuously satisfied on an empty Board, which is the defect recorded in `docs/ai-direction.md` under "An idle indication that fired on your own turn", one screen over: the Joiner would be told their opponent may have stopped playing the instant the page opened, before the Creator had had any opportunity to move. The consequence accepted here — a Creator who never returns leaves the Joiner waiting with no warning — is stated as a known limitation under criterion 13 of Requirement 12.
5. WHILE a Game has not been deleted under Requirement 13, THE Game_Service SHALL retain that Game in its current Game_State when a Player stops sending requests.
6. IF a Player_Session presents no Player_Token for a Game, presents a Player_Token that is invalid or expired, or presents a Player_Token bound to a Game_Id other than that of the requested Game, THEN THE Web_Client SHALL display the same indication stating that the viewer is not a Player in that Game.

### Requirement 10: Observability and Application Security

**User Story:** As the engineer operating the hosted instance, I want health checks, structured logs, rate limits, and request forgery protection, so that I can tell whether the service is healthy and limit abuse of the public endpoints.

#### Acceptance Criteria

1. WHILE the persistence layer is reachable, WHEN the Health_Endpoint receives a request, THE Application SHALL respond within 1 second with a success status and a body reporting the persistence layer as reachable.
2. IF the persistence layer is unreachable, THEN THE Health_Endpoint SHALL respond within 1 second with a failure status and a body reporting the persistence layer as unreachable, and SHALL reserve the success status for the case stated in criterion 1 of this requirement.
3. WHEN a Game is created, a Player joins, a Move is accepted, a Move is rejected, a Game reaches a Terminal_State, or a Rematch is created, THE Game_Service SHALL emit one structured log record for that event.
4. THE Game_Service SHALL include the Game_Id, the event name, and a timestamp in every structured log record it emits for a Game lifecycle event, and WHERE that record describes a move request, THE Game_Service SHALL additionally include the acting Mark, the Cell_Index, the Sequence_Index, and the acceptance outcome in that record.
5. THE Game_Service SHALL redact Player_Token values and Join_Code values from all log output.
6. WHEN the number of join requests from a single Rate_Limit_Subject exceeds 20 within any 60-second window, THE Game_Service SHALL reject further join requests from that Rate_Limit_Subject with a rate-limited outcome for the remainder of that window.
7. WHEN the number of move requests presenting a single Player_Token exceeds 60 within any 60-second window, THE Game_Service SHALL reject further move requests presenting that Player_Token with a rate-limited outcome for the remainder of that window.
8. THE Game_Service SHALL apply to Game state requests either no rate limit or a rate limit separate from the limit stated in criterion 7 of this requirement whose threshold exceeds the request rate required by Requirement 8, so that state requests made at the rate required by Requirement 8 receive no rate-limited outcome.
9. WHEN a request creates a Game, joins a Game, submits a Move, or creates a Rematch, THE Game_Service SHALL verify the origin of that request, and WHERE the origin of that request cannot be established as same-origin, THE Game_Service SHALL additionally require a valid cross-site request forgery token on that request.
10. IF the origin of a state-changing request is not established as same-origin and that request presents no valid cross-site request forgery token, THEN THE Game_Service SHALL reject that request and SHALL leave all Game state unchanged.
11. THE Game_Service SHALL set the Player_Session cookie with the HttpOnly attribute, the SameSite attribute, and, WHERE the Application is served over HTTPS, the Secure attribute.

### Requirement 11: Deterministic Rules Engine

**User Story:** As an engineer, I want the game rules expressed as a pure derivation from the move list, so that the outcome of any board is reproducible and testable without a database or a browser.

#### Acceptance Criteria

1. WHERE a supplied Move_List is a Well_Formed_Move_List, THE Rules_Engine SHALL derive Board occupancy, Mark_To_Move, and Outcome solely from that Move_List.
2. WHEN the Rules_Engine is supplied the same Move_List twice, THE Rules_Engine SHALL report an identical Board, Mark_To_Move, and Outcome for both invocations (replay determinism).
3. WHERE a supplied Move_List is a Well_Formed_Move_List, THE Rules_Engine SHALL report exactly one Outcome from the set `in_progress`, `won_by_X`, `won_by_O`, `drawn` for that Move_List (mutual exclusivity of outcomes).
4. THE Rules_Engine SHALL report the Mark of the Move at any Sequence_Index as `X` for an even Sequence_Index and as `O` for an odd Sequence_Index (mark parity).
5. WHERE a supplied Move_List is not a Well_Formed_Move_List, THE Rules_Engine SHALL halt processing of that Move_List immediately and SHALL report only a single uniform invalid-move-list error state, deriving no Board occupancy, no Mark_To_Move, and no Outcome from that Move_List (invalid move list rejection).
6. THE Game_Service SHALL supply the Rules_Engine only with Move_Lists that are Well_Formed_Move_Lists.
7. WHEN a Move_List of nine Moves completes no Winning_Line, THE Rules_Engine SHALL report the Outcome as `drawn` (draw characterisation).
8. WHEN a Move_List contains three Moves of the same Mark occupying all three Cells of any Winning_Line, THE Rules_Engine SHALL report that Mark as the winner irrespective of the order in which those Cells were played (win detection completeness).
9. THE Rules_Engine SHALL operate without access to the persistence layer, the session, or the transport layer.

### Requirement 12: Documentation and Development Records

**User Story:** As a reviewer, I want documentation that lets me run the application and its tests and understand the decisions taken, so that I can assess the work without reverse-engineering it.

#### Acceptance Criteria

1. THE Application repository SHALL contain a README stating the prerequisites required to run the Application locally.
2. THE README SHALL state the commands that start the Application locally and the URL at which the running Application is reachable.
3. THE README SHALL state the commands that run the automated test suites and the static analysis and formatting checks.
4. THE README SHALL state the URL of the publicly hosted running instance of the Application.
5. THE Application repository SHALL contain the requirements, design, and task documents for this feature.
6. THE Application repository SHALL contain a record of how the AI tooling was directed, comprising the spec documents and the corrections made to the generated output.
7. THE Application repository SHALL contain a decision record for each significant technical choice, stating the decision, the alternatives considered, and the reason for the choice.
8. THE README SHALL state which AI tooling was used, for which parts of the work, and identify the places where the generated output was corrected or rejected.
9. WHEN a change is pushed to the repository or a pull request is opened, THE continuous integration workflow SHALL run the automated test suites and the static analysis and formatting checks and SHALL report a failure status if any check fails.
10. THE README SHALL state that access to a Game cannot be recovered after loss of the Player_Session, and SHALL state that this limitation follows from the deliberate absence of user accounts.
11. THE Application repository SHALL contain a decision record covering the choice of state-synchronisation transport, stating the decision taken, the alternatives considered, and the reason for that decision.
12. THE README SHALL state how to invoke the command required by criterion 3 of Requirement 13 on a schedule as the production means of deleting Games that are Eligible_For_Expiry.
13. THE README SHALL state, as a known limitation, that the opponent-idle indication of criterion 4 of Requirement 9 begins only once a Move has been accepted, so a Creator who joins a Game and never returns leaves the Joiner waiting with no warning.

### Requirement 13: Game Retention and Expiry

**User Story:** As the engineer operating the public hosted instance, I want abandoned and stale games removed by a command I can run on a schedule, so that unauthenticated game creation does not grow stored data without bound.

#### Acceptance Criteria

1. WHILE a Game has a Game_State of `waiting_for_opponent` and no Joiner has been assigned to that Game, WHEN 24 hours have elapsed since that Game was created, THE Game_Service SHALL treat that Game as Eligible_For_Expiry.
2. WHEN 7 days have elapsed since the most recent accepted Move or Game_State change of a Game, THE Game_Service SHALL treat that Game as Eligible_For_Expiry.
3. THE Application SHALL provide a command that, for every Game that is Eligible_For_Expiry, deletes that Game and its Move_List and retains an Expiry_Record for the Game_Id of that Game.
4. THE Game_Service SHALL retain an Expiry_Record for at least 30 days after the deletion of the corresponding Game, and SHALL delete that Expiry_Record thereafter.
5. WHILE a Game is Eligible_For_Expiry and the command required by criterion 3 of this requirement has not yet deleted that Game, THE Game_Service SHALL treat that Game as a Game in its current Game_State, so that the elapsed times stated in criteria 1 and 2 of this requirement are lower bounds on retention rather than exact times of deletion.
6. IF a Player_Session presenting a valid Player_Token requests a Game_Id for which no Game exists and an Expiry_Record exists, THEN THE Game_Service SHALL respond with a game-expired outcome that is distinct from the not-authorised outcome and from the not-recognised outcome, and THE Web_Client SHALL display an indication that the Game is no longer available.
7. IF a move request or a Rematch request targets a Game_Id for which no Game exists and an Expiry_Record exists, THEN THE Game_Service SHALL reject the request with the game-expired outcome and SHALL create no Game and no Move.
8. WHERE no Game and no Expiry_Record exist for a requested Game_Id, THE Game_Service SHALL respond with the not-recognised outcome.

### Requirement 14: Automated Verification

**User Story:** As a reviewer, I want executable evidence that the stated game rules and authorisation rules hold, so that I can accept the behaviour described in this document without re-deriving it from the implementation.

#### Acceptance Criteria

1. THE Application repository SHALL contain unit tests that exercise the Rules_Engine without access to the persistence layer, the Player_Session, or the HTTP transport layer.
2. THE Application repository SHALL contain tests that verify each correctness property named in Requirement 11 (replay determinism, mutual exclusivity of outcomes, draw characterisation, and win detection completeness) by exhaustive enumeration of the complete set of reachable Well_Formed_Move_Lists rather than by selected examples, and SHALL assert that the enumeration yields 549,946 reachable Move_Lists and that 255,168 of those Move_Lists are the Move_List of a Game in a Terminal_State.
3. THE Application repository SHALL contain feature tests that exercise each distinct rejection outcome named in Requirements 2, 3, 4, 5, 7, and 13, together with the rate-limited outcome named in criterion 6 of Requirement 10, SHALL assert that each of those rejections returns its own distinct outcome value, and SHALL exclude the cross-site request forgery rejection stated in criterion 10 of Requirement 10 from that coverage.
4. THE Application repository SHALL contain a test of the join rate limit stated in criterion 6 of Requirement 10 that asserts that the twentieth join request from a single Rate_Limit_Subject within one 60-second window receives no rate-limited outcome and that the twenty-first such request is rejected with the rate-limited outcome.
5. THE Application repository SHALL contain one end-to-end test that drives two separate Player_Sessions through a Game from creation to a Terminal_State.
6. THE Application repository SHALL contain a test asserting that a request presenting no valid Player_Token for a Game receives no Board, no Move_List, no Game_State, and no Mark_To_Move for that Game, as required by criterion 10 of Requirement 3.
7. THE Application repository SHALL contain a test covering the command required by criterion 3 of Requirement 13 that deletes Games that are Eligible_For_Expiry.
8. THE Application repository SHALL contain tests supplying the Rules_Engine with Move_Lists that are not Well_Formed_Move_Lists, covering a repeated Cell_Index, a Cell_Index outside the range 0 to 8 inclusive, a Sequence_Index gap, a length exceeding nine, and a Move following a Move that completes a Winning_Line, and asserting that each of those Move_Lists yields the invalid-move-list error state.
9. THE Application repository SHALL contain tests of the join rejection stated in criterion 7 of Requirement 2 and of the conflict outcome stated in criterion 4 of Requirement 5 that establish the Game state each request would observe and then submit the requests one after another, so that each test asserts the outcome that the concurrent case produces without issuing requests in parallel.
